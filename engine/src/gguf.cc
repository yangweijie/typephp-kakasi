// GGUF v3 reader: header, metadata, tensor directory, and a self-validating byte
// accounting check. Pure C++ (no SYCL), so cl compiles it alongside the AOT output.
//
// The byte check is the loader's oracle: sum of per-tensor byte sizes must equal
// (file size - aligned data start). A wrong dtype table or a mis-parsed directory
// cannot pass it, so a green validate() means the tensor map is trustworthy.
#include <phpx.h>

#include "gguf.h"

#include <cstdint>
#include <cstdlib>
#include <cstring>
#include <fstream>
#include <map>
#include <sstream>
#include <string>
#include <vector>

namespace gguf {
namespace {

    struct TypeEntry {
        uint32_t dtype;
        uint64_t blk;   // elements per block
        uint64_t tsz;   // bytes per block
        const char* name;
    };

    // ggml type numbering has legacy holes (4/5 removed): do not assume contiguity.
    const TypeEntry kTypes[] = {
        {0, 1, 4, "f32"},     {1, 1, 2, "f16"},      {2, 32, 18, "q4_0"},  {3, 32, 20, "q4_1"},
        {6, 32, 22, "q5_0"},  {7, 32, 24, "q5_1"},   {8, 32, 34, "q8_0"},  {9, 32, 36, "q8_1"},
        {10, 256, 84, "q2_k"}, {11, 256, 110, "q3_k"}, {12, 256, 144, "q4_k"},
        {13, 256, 168, "q5_k"}, {14, 256, 210, "q6_k"}, {15, 256, 292, "q8_k"},
        {20, 32, 18, "iq4_nl"}, {30, 1, 2, "bf16"},
    };

    const TypeEntry* find_type(uint32_t dtype) {
        for (const auto& t : kTypes)
            if (t.dtype == dtype) return &t;
        return nullptr;
    }

    struct Tensor {
        std::string name;
        uint64_t ne[4] = {1, 1, 1, 1};
        uint32_t dtype = 0;
        uint64_t off = 0;
        uint64_t bytes = 0;
    };

    std::vector<uint8_t> g_buf;
    std::vector<Tensor> g_tensors;
    std::map<std::string, std::string> g_meta_str;
    std::map<std::string, std::vector<std::string>> g_meta_arr;
    std::map<std::string, double> g_meta_num;
    std::string g_error;
    std::string g_path;
    uint64_t g_version = 0, g_n_tensors = 0, g_n_kv = 0, g_data_start = 0, g_avail = 0;
    uint64_t g_align = 32;
    bool g_loaded = false;

    struct Cursor {
        const uint8_t* p;
        size_t left;
        bool ok = true;

        uint64_t need(size_t n) {
            if (left < n) { ok = false; return 0; }
            left -= n;
            const uint8_t* r = p;
            p += n;
            return (uint64_t) (uintptr_t) r;
        }
        uint32_t u32() {
            if (!need(4)) return 0;
            const uint8_t* r = p - 4;
            return (uint32_t) r[0] | ((uint32_t) r[1] << 8) | ((uint32_t) r[2] << 16) | ((uint32_t) r[3] << 24);
        }
        int32_t i32() { return (int32_t) u32(); }
        uint64_t u64() {
            if (!need(8)) return 0;
            const uint8_t* r = p - 8;
            uint64_t v = 0;
            for (int i = 0; i < 8; ++i) v |= (uint64_t) r[i] << (8 * i);
            return v;
        }
        float f32() {
            uint32_t bits = u32();
            float out;
            memcpy(&out, &bits, 4);
            return out;
        }
        double f64() {
            if (!need(8)) return 0.0;
            const uint8_t* r = p - 8;
            double v;
            memcpy(&v, r, 8);
            return v;
        }
        std::string str() {
            uint64_t len = u64();
            if (!ok || len > left) { ok = false; return ""; }
            std::string s((const char*) p, (size_t) len);
            need((size_t) len);
            return s;
        }
        void skip(size_t n) { need(n); }
    };

    // Scalar size by gguf value type; STR/ARR handled separately.
    size_t scalar_size(uint32_t t) {
        switch (t) {
            case 0: case 1: case 7: return 1;
            case 2: case 3: return 2;
            case 4: case 5: case 6: return 4;
            case 10: case 11: case 12: return 8;
            default: return 0;
        }
    }

    bool read_scalar(Cursor& c, uint32_t t, const std::string& key) {
        const size_t es = scalar_size(t);
        if (es == 0 || t == 8 || t == 9) return false;
        if (!c.need(es)) return false;
        const uint8_t* r = c.p - es;
        double v = 0.0;
        switch (t) {
            case 0: v = r[0]; break;
            case 1: v = (double) (int8_t) r[0]; break;
            case 2: v = (double) (uint16_t)(r[0] | (r[1] << 8)); break;
            case 3: v = (double) (int16_t)(r[0] | (r[1] << 8)); break;
            case 4:
            case 5: {
                uint32_t x = (uint32_t) r[0] | ((uint32_t) r[1] << 8) | ((uint32_t) r[2] << 16) | ((uint32_t) r[3] << 24);
                v = (t == 4) ? (double) x : (double) (int32_t) x;
                break;
            }
            case 6: { float f; memcpy(&f, r, 4); v = (double) f; break; }
            case 7: v = r[0] ? 1.0 : 0.0; break;
            case 10:
            case 11: {
                uint64_t x = 0;
                for (int i = 7; i >= 0; --i) x = (x << 8) | r[i];
                v = (t == 10) ? (double) x : (double) (int64_t) x;
                break;
            }
            case 12: { double d; memcpy(&d, r, 8); v = d; break; }
            default: return false;
        }
        if (!key.empty()) g_meta_num[key] = v;
        return true;
    }

    uint64_t align_up(uint64_t v, uint64_t a) { return (v + a - 1) / a * a; }

}  // namespace

bool load(const std::string& path) {
    g_loaded = false;
    g_error.clear();
    g_tensors.clear();
    g_meta_str.clear();
    g_meta_arr.clear();
    g_meta_num.clear();
    g_path = path;

    std::ifstream in(path, std::ios::binary);
    if (!in) { g_error = "cannot open " + path; return false; }
    in.seekg(0, std::ios::end);
    const uint64_t fsize = (uint64_t) in.tellg();
    in.seekg(0);
    const size_t want = (size_t) (fsize < (16u << 20) ? fsize : (16u << 20));
    g_buf.assign(want, 0);
    in.read((char*) g_buf.data(), (std::streamsize) want);
    if (!in) { g_error = "short read of header region"; return false; }

    Cursor c{g_buf.data(), want};
    if (c.need(4)) {
        if (memcmp(c.p - 4, "GGUF", 4) != 0) { g_error = "not a GGUF file"; return false; }
    } else { g_error = "file too small"; return false; }

    g_version = c.u32();
    g_n_tensors = c.u64();
    g_n_kv = c.u64();
    if (!c.ok) { g_error = "truncated header"; return false; }
    if (g_version < 2 || g_version > 3) { g_error = "unsupported gguf version " + std::to_string(g_version); return false; }
    if (g_n_tensors > 100000 || g_n_kv > 100000) { g_error = "implausible header counts"; return false; }

    for (uint64_t i = 0; i < g_n_kv; ++i) {
        std::string key = c.str();
        uint32_t t = c.u32();
        if (!c.ok) { g_error = "truncated kv pair " + std::to_string(i); return false; }
        if (t == 8) {
            std::string v = c.str();
            if (!c.ok) { g_error = "truncated string value " + key; return false; }
            g_meta_str[key] = v;
        } else if (t == 9) {
            uint32_t elem = c.u32();
            uint64_t n = c.u64();
            if (!c.ok) { g_error = "truncated array header " + key; return false; }
            if (elem == 8) {
                std::vector<std::string> all;
                all.reserve((size_t) n);
                std::string preview;
                for (uint64_t j = 0; j < n; ++j) {
                    std::string s = c.str();
                    if (!c.ok) { g_error = "truncated array item " + key; return false; }
                    if (j < 3) preview += "|" + s;
                    all.push_back(std::move(s));
                }
                g_meta_num[key + ".len"] = (double) n;
                std::ostringstream os;
                os << n;
                g_meta_str[key] = os.str() + preview;
                g_meta_arr[key] = std::move(all);
            } else if (elem == 5) {
                // INT32 arrays are materialized (as decimal text) because token_type is one:
                // the tokenizer needs to know which vocab entries are CONTROL, and guessing
                // from the "<| ... |>" spelling would break on any model that names them else.
                std::vector<std::string> all;
                all.reserve((size_t) n);
                for (uint64_t j = 0; j < n; ++j) {
                    const int32_t v = c.i32();
                    if (!c.ok) { g_error = "truncated int array body " + key; return false; }
                    all.push_back(std::to_string(v));
                }
                g_meta_num[key + ".len"] = (double) n;
                g_meta_arr[key] = std::move(all);
            } else {
                size_t es = scalar_size(elem);
                if (es == 0 || elem == 9) { g_error = "unsupported array element type in " + key; return false; }
                c.skip((size_t) (n * es));
                if (!c.ok) { g_error = "truncated array body " + key; return false; }
                g_meta_num[key + ".len"] = (double) n;
            }
        } else {
            size_t es = scalar_size(t);
            if (es == 0) { g_error = "unsupported value type " + std::to_string(t) + " for " + key; return false; }
            if (!read_scalar(c, t, key)) { g_error = "truncated value " + key; return false; }
        }
    }

    auto it = g_meta_str.find("general.alignment");
    if (it != g_meta_str.end()) g_align = (uint64_t) strtoull(it->second.c_str(), nullptr, 10);
    if (g_align == 0) g_align = 32;

    for (uint64_t i = 0; i < g_n_tensors; ++i) {
        Tensor ti;
        ti.name = c.str();
        uint32_t nd = c.u32();
        if (!c.ok || nd > 4) { g_error = "bad tensor info at " + std::to_string(i); return false; }
        for (uint32_t d = 0; d < nd; ++d) ti.ne[d] = c.u64();
        ti.dtype = c.u32();
        ti.off = c.u64();
        if (!c.ok) { g_error = "truncated tensor info at " + std::to_string(i); return false; }

        const TypeEntry* te = find_type(ti.dtype);
        if (!te) { g_error = "unsupported dtype " + std::to_string(ti.dtype) + " on " + ti.name; return false; }
        uint64_t numel = 1;
        for (uint32_t d = 0; d < nd; ++d) numel *= ti.ne[d];
        if (numel % te->blk != 0) {
            g_error = "element count of " + ti.name + " not divisible by block " + std::to_string(te->blk);
            return false;
        }
        ti.bytes = numel / te->blk * te->tsz;
        g_tensors.push_back(std::move(ti));
    }

    g_data_start = align_up((uint64_t) (c.p - g_buf.data()), g_align);
    if (g_data_start > fsize) { g_error = "data start past end of file"; return false; }
    g_avail = fsize - g_data_start;
    g_loaded = true;
    return true;
}

std::string error() { return g_error; }

std::string validate() {
    if (!g_loaded) return "NOT LOADED: " + g_error;
    uint64_t sum = 0, maxend = 0;
    std::map<uint32_t, uint64_t> by_type;
    for (const auto& t : g_tensors) {
        sum += t.bytes;
        by_type[t.dtype] += t.bytes;
        if (t.off + t.bytes > maxend) maxend = t.off + t.bytes;
    }
    std::ostringstream os;
    os << "gguf v" << g_version << " tensors=" << g_tensors.size() << " kv=" << g_n_kv
       << " align=" << g_align << " data_start=" << g_data_start << " avail=" << g_avail
       << " sum_bytes=" << sum << " max_end=" << maxend;
    if (sum == g_avail && maxend == g_avail) os << " ACCOUNTING=PASS";
    else os << " ACCOUNTING=FAIL delta=" << ((int64_t) g_avail - (int64_t) sum);
    return os.str();
}

int tensor_count() { return g_loaded ? (int) g_tensors.size() : 0; }

// Predicate form of validate(): the loader's own correctness oracle.
bool accounting_ok() {
    if (!g_loaded) return false;
    uint64_t sum = 0, maxend = 0;
    for (const auto& t : g_tensors) {
        sum += t.bytes;
        if (t.off + t.bytes > maxend) maxend = t.off + t.bytes;
    }
    return sum == g_avail && maxend == g_avail;
}

std::string tensor_name(int i) { return (i >= 0 && i < (int) g_tensors.size()) ? g_tensors[i].name : ""; }

int tensor_dtype(int i) { return (i >= 0 && i < (int) g_tensors.size()) ? (int) g_tensors[i].dtype : -1; }

std::string tensor_dtype_name(int i) {
    if (i < 0 || i >= (int) g_tensors.size()) return "";
    const TypeEntry* te = find_type(g_tensors[i].dtype);
    return te ? te->name : "unknown";
}

int64_t tensor_ne(int i, int d) {
    if (i < 0 || i >= (int) g_tensors.size() || d < 0 || d > 3) return -1;
    return (int64_t) g_tensors[i].ne[d];
}

int64_t tensor_bytes(int i) { return (i >= 0 && i < (int) g_tensors.size()) ? (int64_t) g_tensors[i].bytes : -1; }
int64_t tensor_offset(int i) { return (i >= 0 && i < (int) g_tensors.size()) ? (int64_t) g_tensors[i].off : -1; }

std::string meta_str(const std::string& key) {
    auto it = g_meta_str.find(key);
    return it == g_meta_str.end() ? "" : it->second;
}

int arr_size(const std::string& key) {
    auto it = g_meta_arr.find(key);
    return it == g_meta_arr.end() ? 0 : (int) it->second.size();
}

std::string arr_at(const std::string& key, int i) {
    auto it = g_meta_arr.find(key);
    if (it == g_meta_arr.end() || i < 0 || i >= (int) it->second.size()) return "";
    return it->second[(size_t) i];
}

const std::vector<std::string>* arr_ptr(const std::string& key) {
    auto it = g_meta_arr.find(key);
    return it == g_meta_arr.end() ? nullptr : &it->second;
}

std::string token_at(int id) {

    auto it = g_meta_arr.find("tokenizer.ggml.tokens");
    if (it == g_meta_arr.end() || id < 0 || id >= (int) it->second.size()) return "";
    return it->second[(size_t) id];
}

double meta_num(const std::string& key) {
    auto it = g_meta_num.find(key);
    return it == g_meta_num.end() ? 0.0 : it->second;
}

int64_t data_start() { return (int64_t) g_data_start; }
int64_t avail() { return (int64_t) g_avail; }

bool loaded() { return g_loaded; }

int find_tensor(const std::string& name) {    for (size_t i = 0; i < g_tensors.size(); ++i)
        if (g_tensors[i].name == name) return (int) i;
    return -1;
}

bool raw_bytes(int index, std::vector<uint8_t>& out) {
    out.clear();
    if (!g_loaded || index < 0 || index >= (int) g_tensors.size()) { g_error = "raw_bytes: bad index"; return false; }
    const Tensor& t = g_tensors[index];
    std::ifstream in(g_path, std::ios::binary);
    if (!in) { g_error = "raw_bytes: cannot reopen " + g_path; return false; }
    const uint64_t pos = g_data_start + t.off;
    in.seekg((std::streamoff) pos);
    if (!in) { g_error = "raw_bytes: seek failed at " + std::to_string(pos); return false; }
    out.resize((size_t) t.bytes);
    in.read((char*) out.data(), (std::streamsize) t.bytes);
    if ((uint64_t) in.gcount() != t.bytes) { g_error = "raw_bytes: short read on " + t.name; out.clear(); return false; }
    return true;
}

bool raw_bytes_range(int index, uint64_t byte_off, uint64_t len, std::vector<uint8_t>& out) {
    out.clear();
    if (!g_loaded || index < 0 || index >= (int) g_tensors.size()) { g_error = "raw_bytes_range: bad index"; return false; }
    if (byte_off + len > g_tensors[index].bytes) { g_error = "raw_bytes_range: beyond tensor"; return false; }
    std::ifstream in(g_path, std::ios::binary);
    if (!in) { g_error = "raw_bytes_range: cannot open"; return false; }
    in.seekg((std::streamoff) (g_data_start + g_tensors[index].off + byte_off));
    if (!in) { g_error = "raw_bytes_range: seek failed"; return false; }
    out.resize((size_t) len);
    in.read((char*) out.data(), (std::streamsize) len);
    if ((uint64_t) in.gcount() != len) { g_error = "raw_bytes_range: short read"; out.clear(); return false; }
    return true;
}

}  // namespace gguf

using namespace php;

php::Bool php_gguf_load(php::Str path) {
    return php::Bool(gguf::load(std::string(path.data(), path.length())));
}

php::Str php_gguf_error() { return php::Str(gguf::error()); }
php::Str php_gguf_validate() { return php::Str(gguf::validate()); }
php::Int php_gguf_tensor_count() { return php::Int(gguf::tensor_count()); }

php::Str php_gguf_tensor_name(php::Int i) { return php::Str(gguf::tensor_name((int) i)); }
php::Str php_gguf_tensor_dtype_name(php::Int i) { return php::Str(gguf::tensor_dtype_name((int) i)); }
php::Int php_gguf_tensor_dtype(php::Int i) { return php::Int(gguf::tensor_dtype((int) i)); }
php::Int php_gguf_tensor_ne(php::Int i, php::Int d) { return php::Int(gguf::tensor_ne((int) i, (int) d)); }
php::Int php_gguf_tensor_bytes(php::Int i) { return php::Int(gguf::tensor_bytes((int) i)); }
php::Int php_gguf_tensor_offset(php::Int i) { return php::Int(gguf::tensor_offset((int) i)); }

php::Str php_gguf_meta_str(php::Str key) { return php::Str(gguf::meta_str(std::string(key.data(), key.length()))); }
php::Str php_gguf_token_str(php::Int id) { return php::Str(gguf::token_at((int) id)); }
// Token ids whose decoded text is non-empty, contains no control marker ("<|"), and uses only
// bytes from `charset` (Ġ/Ċ are decoded to space/newline first). This is the vocabulary side of
// constrained string decoding: the caller can then only pick tokens inside that set.
php::Str php_gguf_charset_ids(php::Str charset) {
    const std::string set((const char*) charset.data(), charset.length());
    bool allow[256] = {false};
    for (size_t i = 0; i < set.size(); ++i) allow[(unsigned char) set[i]] = true;
    std::string out;
    const std::vector<std::string>* toks = gguf::arr_ptr("tokenizer.ggml.tokens");
    if (!toks) return php::Str(out);
    for (size_t i = 0; i < toks->size(); ++i) {
        const std::string& t = (*toks)[i];
        if (t.empty()) continue;
        std::string dec;
        for (size_t j = 0; j < t.size();) {
            const unsigned char c = (unsigned char) t[j];
            if (c == 0xC4 && j + 1 < t.size()) {
                const unsigned char d = (unsigned char) t[j + 1];
                if (d == 0xA0) { dec += ' '; j += 2; continue; }
                if (d == 0x8A) { dec += '\n'; j += 2; continue; }
            }
            dec += (char) c;
            j += 1;
        }
        if (dec.find("<|") != std::string::npos) continue;
        bool ok = true;
        for (size_t j = 0; j < dec.size(); ++j) {
            if (!allow[(unsigned char) dec[j]]) { ok = false; break; }
        }
        if (!ok) continue;
        if (!out.empty()) out += ',';
        out += std::to_string(i);
    }
    return php::Str(out);
}
php::Float php_gguf_meta_num(php::Str key) { return php::Float(gguf::meta_num(std::string(key.data(), key.length()))); }
php::Int php_gguf_data_start() { return php::Int(gguf::data_start()); }
php::Int php_gguf_avail() { return php::Int(gguf::avail()); }
php::Bool php_gguf_accounting_ok() { return php::Bool(gguf::accounting_ok()); }
