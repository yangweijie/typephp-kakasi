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
        uint32_t nd = 0;   // F682: declared rank, kept verbatim for shard rewriting
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
    // F682: sharded loading. When a model arrives as a GGUF shard set
    // (<name>-00001-of-00002.gguf ...), the tensors are merged into one logical model:
    // each tensor remembers which shard file holds its bytes and raw_bytes routes there.
    // Metadata comes from the first shard; split.no/split.count are validated when present.
    bool g_sharded = false;
    std::vector<std::string> g_shard_paths;
    std::vector<uint64_t> g_shard_data_start;
    std::vector<uint64_t> g_shard_fsize;
    std::vector<int> g_tensor_shard;   // tensor index -> shard index
    uint64_t g_kv_end = 0;             // byte offset where the KV TLVs end (tensor infos start)
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
    // F682: a plain single-file load also clears any sharded state - the tensor-to-shard
    // routing must never survive a load that did not build it.
    g_sharded = false;
    g_shard_paths.clear();
    g_shard_data_start.clear();
    g_shard_fsize.clear();
    g_tensor_shard.clear();
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

    g_kv_end = (uint64_t) (c.p - g_buf.data());   // F682: KV TLVs end here (shard writer needs the raw bytes)

    auto it = g_meta_str.find("general.alignment");
    if (it != g_meta_str.end()) g_align = (uint64_t) strtoull(it->second.c_str(), nullptr, 10);
    if (g_align == 0) g_align = 32;

    for (uint64_t i = 0; i < g_n_tensors; ++i) {
        Tensor ti;
        ti.name = c.str();
        uint32_t nd = c.u32();
        if (!c.ok || nd > 4) { g_error = "bad tensor info at " + std::to_string(i); return false; }
        ti.nd = nd;
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
    std::ostringstream os;
    os << "gguf v" << g_version << " tensors=" << g_tensors.size() << " kv=" << g_n_kv
       << " align=" << g_align;
    if (g_sharded) {
        // F682: sharded accounting - every shard's tensor bytes must exactly fill the
        // span between its data start and its file end (the same oracle as the single
        // file's sum==avail, applied per shard).
        uint64_t sum = 0;
        bool ok = true;
        std::vector<uint64_t> maxend(g_shard_paths.size(), 0);
        for (size_t i = 0; i < g_tensors.size(); ++i) {
            const int k = g_tensor_shard[(size_t) i];
            const Tensor& t = g_tensors[i];
            sum += t.bytes;
            if (t.off + t.bytes > maxend[(size_t) k]) maxend[(size_t) k] = t.off + t.bytes;
        }
        for (size_t k = 0; k < maxend.size(); ++k) {
            // The shard last tensor must end exactly at the file end: no trailing junk.
            // In-shard alignment pads between tensors are legal.
            const uint64_t span = g_shard_fsize[k] - g_shard_data_start[k];
            if (maxend[k] != span) ok = false;
        }
        os << " shards=" << g_shard_paths.size() << " sum_bytes=" << sum;
        os << (ok ? " ACCOUNTING=PASS" : " ACCOUNTING=FAIL");
        return os.str();
    }
    uint64_t sum = 0, maxend = 0;
    std::map<uint32_t, uint64_t> by_type;
    for (const auto& t : g_tensors) {
        sum += t.bytes;
        by_type[t.dtype] += t.bytes;
        if (t.off + t.bytes > maxend) maxend = t.off + t.bytes;
    }
    os << " data_start=" << g_data_start << " avail=" << g_avail
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

std::string token_decode(int id) {
    const std::string encoded = token_at(id);
    std::string out;
    for (size_t i = 0; i < encoded.size();) {
        const unsigned char lead = (unsigned char) encoded[i];
        uint32_t cp;
        size_t width;
        if (lead < 0x80) { cp = lead; width = 1; }
        else if ((lead & 0xE0) == 0xC0 && i + 1 < encoded.size()) {
            cp = ((uint32_t) (lead & 0x1F) << 6) | ((unsigned char) encoded[i + 1] & 0x3F);
            width = 2;
        } else if ((lead & 0xF0) == 0xE0 && i + 2 < encoded.size()) {
            cp = ((uint32_t) (lead & 0x0F) << 12)
                | ((uint32_t) ((unsigned char) encoded[i + 1] & 0x3F) << 6)
                | ((unsigned char) encoded[i + 2] & 0x3F);
            width = 3;
        } else if ((lead & 0xF8) == 0xF0 && i + 3 < encoded.size()) {
            cp = ((uint32_t) (lead & 0x07) << 18)
                | ((uint32_t) ((unsigned char) encoded[i + 1] & 0x3F) << 12)
                | ((uint32_t) ((unsigned char) encoded[i + 2] & 0x3F) << 6)
                | ((unsigned char) encoded[i + 3] & 0x3F);
            width = 4;
        } else {
            out.append(encoded, i, 1);
            ++i;
            continue;
        }
        if ((cp >= 33 && cp <= 126) || (cp >= 161 && cp <= 172) || (cp >= 174 && cp <= 255)) {
            out.push_back((char) cp);
        } else if (cp >= 256 && cp <= 323) {
            const uint32_t index = cp - 256;
            out.push_back((char) (index < 33 ? index : (index < 67 ? 127 + index - 33 : 173)));
        } else {
            out.append(encoded, i, width);
        }
        i += width;
    }
    return out;
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
    // F682: in a sharded model each tensor's bytes live in its own shard file.
    const std::string& file = g_sharded ? g_shard_paths[(size_t) g_tensor_shard[(size_t) index]] : g_path;
    const uint64_t ds = g_sharded ? g_shard_data_start[(size_t) g_tensor_shard[(size_t) index]] : g_data_start;
    std::ifstream in(file, std::ios::binary);
    if (!in) { g_error = "raw_bytes: cannot reopen " + file; return false; }
    const uint64_t pos = ds + t.off;
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
    const std::string& file2 = g_sharded ? g_shard_paths[(size_t) g_tensor_shard[(size_t) index]] : g_path;
    const uint64_t ds2 = g_sharded ? g_shard_data_start[(size_t) g_tensor_shard[(size_t) index]] : g_data_start;
    std::ifstream in(file2, std::ios::binary);
    if (!in) { g_error = "raw_bytes_range: cannot open"; return false; }
    in.seekg((std::streamoff) (ds2 + g_tensors[index].off + byte_off));
    if (!in) { g_error = "raw_bytes_range: seek failed"; return false; }
    out.resize((size_t) len);
    in.read((char*) out.data(), (std::streamsize) len);
    if ((uint64_t) in.gcount() != len) { g_error = "raw_bytes_range: short read"; out.clear(); return false; }
    return true;
}

// F682 helpers -----------------------------------------------------------------

void w_u16(std::ofstream& o, uint16_t v) {
    uint8_t b[2] = {(uint8_t) (v & 0xff), (uint8_t) (v >> 8)};
    o.write((const char*) b, 2);
}
void w_u32(std::ofstream& o, uint32_t v) {
    uint8_t b[4];
    for (int i = 0; i < 4; ++i) b[i] = (uint8_t) (v >> (8 * i));
    o.write((const char*) b, 4);
}
void w_u64(std::ofstream& o, uint64_t v) {
    uint8_t b[8];
    for (int i = 0; i < 8; ++i) b[i] = (uint8_t) (v >> (8 * i));
    o.write((const char*) b, 8);
}
void w_str(std::ofstream& o, const std::string& v) {
    w_u64(o, (uint64_t) v.size());
    o.write(v.data(), (std::streamsize) v.size());
}

// Loads a GGUF SHARD SET: <prefix>-00001-of-0000N.gguf ... <prefix>-0000N-of-0000N.gguf.
// The first shard may be ANY member of the set - the loader derives the prefix and the
// count from its name, then merges every shard's tensor directory into one logical model.
// Metadata comes from shard 1; split.no/split.count are validated when present.
bool load_sharded(const std::string& first) {
    g_loaded = false;
    g_error.clear();
    // Derive <dir> <prefix> <i> <n> from the file name.
    size_t slash = first.find_last_of("/\\");
    const std::string dir = slash == std::string::npos ? "" : first.substr(0, slash + 1);
    const std::string name = slash == std::string::npos ? first : first.substr(slash + 1);
    const std::string suffix = "-of-";
    size_t of = name.rfind(suffix);
    size_t dot = name.rfind(".gguf");
    if (of == std::string::npos || dot == std::string::npos || dot < of
        || name.size() < 12) {
        g_error = "load_sharded: name is not a shard (" + name + ")";
        return false;
    }
    // <prefix>-0000i-of-0000n.gguf: i sits between the last '-' before "-of-" and of.
    size_t dash = of > 0 ? name.rfind('-', of - 1) : std::string::npos;
    if (dash == std::string::npos || dash == 0 || of - dash != 6) {
        g_error = "load_sharded: bad shard ordinal in " + name;
        return false;
    }
    const std::string prefix = name.substr(0, dash);
    const std::string nstr = name.substr(of + 4, dot - (of + 4));
    if (nstr.size() != 5) {
        g_error = "load_sharded: bad shard count field in " + name;
        return false;
    }
    const int n = std::atoi(nstr.c_str());
    if (n < 2 || n > 64) {
        g_error = "load_sharded: implausible shard count " + nstr;
        return false;
    }
    auto pad5 = [](int v) {
        std::ostringstream os;
        os << v;
        std::string r = os.str();
        while (r.size() < 5) r = "0" + r;
        return r;
    };
    struct ShardMeta {
        std::vector<Tensor> tensors;
        std::map<std::string, std::string> meta_str;
        std::map<std::string, std::vector<std::string>> meta_arr;
        std::map<std::string, double> meta_num;
        uint64_t data_start = 0, avail = 0, version = 0, align = 32;
    };
    std::vector<ShardMeta> shards;
    // F682: collected LOCALLY first - load(p) below clears the global shard containers
    // on every call, so anything pushed into them inside this loop would be wiped by
    // the next iteration (that was the heap corruption the first run hit: two shards
    // merged while only one shard path survived).
    std::vector<std::string> shard_paths;
    std::vector<uint64_t> shard_ds;
    std::vector<uint64_t> shard_fsize;
    for (int i = 1; i <= n; ++i) {
        const std::string p = dir + prefix + "-" + pad5(i) + "-of-" + pad5(n) + ".gguf";
        if (!load(p)) {
            if (g_error.find("cannot open") != std::string::npos) {
                g_error = "load_sharded: missing shard " + std::to_string(i) + "/" + std::to_string(n)
                        + " (" + p + ")";
            }
            return false;
        }
        // Split metadata, when the writer recorded it, must agree with the names.
        const double no = meta_num("split.no");
        const double cnt = meta_num("split.count");
        if (cnt != 0 && (int) cnt != n) {
            g_error = "load_sharded: split.count " + std::to_string((int) cnt) + " != name count "
                    + std::to_string(n);
            return false;
        }
        if (no != 0 && (int) no != i) {
            g_error = "load_sharded: split.no " + std::to_string((int) no) + " != position "
                    + std::to_string(i);
            return false;
        }
        ShardMeta sm;
        sm.tensors = g_tensors;
        sm.meta_str = g_meta_str;
        sm.meta_arr = g_meta_arr;
        sm.meta_num = g_meta_num;
        sm.data_start = g_data_start;
        sm.avail = g_avail;
        sm.version = g_version;
        sm.align = g_align;
        shards.push_back(std::move(sm));
        shard_paths.push_back(p);
        shard_ds.push_back(g_data_start);
        shard_fsize.push_back(g_data_start + g_avail);
    }
    g_shard_paths = shard_paths;
    g_shard_data_start = shard_ds;
    g_shard_fsize = shard_fsize;
    for (size_t k = 1; k < shards.size(); ++k) {
        if (shards[k].version != shards[0].version || shards[k].align != shards[0].align) {
            g_error = "load_sharded: shard " + std::to_string(k + 1) + " version/alignment mismatch";
            return false;
        }
    }
    // Merge: tensors concatenate in shard order; metadata is shard 1's.
    g_tensors.clear();
    g_tensor_shard.clear();
    for (size_t k = 0; k < shards.size(); ++k) {
        for (const auto& t : shards[k].tensors) {
            g_tensors.push_back(t);
            g_tensor_shard.push_back((int) k);
        }
    }
    g_meta_str = shards[0].meta_str;
    g_meta_arr = shards[0].meta_arr;
    g_meta_num = shards[0].meta_num;
    g_version = shards[0].version;
    g_n_tensors = (uint64_t) g_tensors.size();
    g_n_kv = 0;   // shard-level count; the merged model has no single KV section
    g_align = shards[0].align;
    g_data_start = 0;
    g_avail = 0;
    g_sharded = true;
    g_loaded = true;
    return true;
}

// Writes a 2-shard split of an already-parsed model: shard 1 takes the first half of the
// tensor directory, shard 2 the rest. The KV TLV bytes are copied verbatim from the
// source header, split.no/split.count are appended, and every tensor's data bytes are
// copied at their new (aligned) offsets. Idempotent per directory contents: existing
// output files are rewritten.
bool make_two_shards(const std::string& src, const std::string& dir) {
    if (!load(src)) return false;
    const int nT = (int) g_tensors.size();
    if (nT < 2) { g_error = "make_two_shards: fewer than 2 tensors"; return false; }
    // Full header region (magic..KV end) - g_buf may only hold the first 16 MiB, so read
    // the KV bytes straight from the file.
    std::ifstream sin(src, std::ios::binary);
    if (!sin) { g_error = "make_two_shards: cannot reopen " + src; return false; }
    std::vector<uint8_t> kv(g_kv_end);
    sin.read((char*) kv.data(), (std::streamsize) kv.size());
    if ((uint64_t) sin.gcount() != kv.size()) { g_error = "make_two_shards: short header read"; return false; }

    auto slash = src.find_last_of("/\\");
    const std::string stem = (slash == std::string::npos ? src : src.substr(slash + 1));
    const std::string base = stem.substr(0, stem.rfind(".gguf"));
    auto shard_name = [&](int k) {
        return dir + "/" + base + "-0000" + std::to_string(k + 1) + "-of-00002.gguf";
    };
    const int half = (nT + 1) / 2;
    for (int k = 0; k < 2; ++k) {
        const int lo = k == 0 ? 0 : half;
        const int hi = k == 0 ? half : nT;
        // Local offsets: each tensor's data is aligned to g_align inside its shard.
        std::vector<uint64_t> offs((size_t) (hi - lo), 0);
        uint64_t cur = 0;
        for (int t = lo; t < hi; ++t) {
            cur = align_up(cur, g_align);
            offs[(size_t) (t - lo)] = cur;
            cur += g_tensors[(size_t) t].bytes;
        }
        std::ofstream o(shard_name(k), std::ios::binary | std::ios::trunc);
        if (!o) { g_error = "make_two_shards: cannot write " + shard_name(k); return false; }
        o.write("GGUF", 4);
        w_u32(o, (uint32_t) g_version);
        w_u64(o, (uint64_t) (hi - lo));
        w_u64(o, g_n_kv + 2);
        o.write((const char*) (kv.data() + 24), (std::streamsize) (kv.size() - 24));
        // split.no / split.count (uint16 scalar, GGUF type 3)
        w_str(o, "split.no");
        w_u32(o, 3);
        w_u16(o, (uint16_t) (k + 1));
        w_str(o, "split.count");
        w_u32(o, 3);
        w_u16(o, 2);
        for (int t = lo; t < hi; ++t) {
            const Tensor& t0 = g_tensors[(size_t) t];
            w_str(o, t0.name);
            w_u32(o, t0.nd);
            for (uint32_t d = 0; d < t0.nd; ++d) w_u64(o, t0.ne[d]);
            w_u32(o, t0.dtype);
            w_u64(o, offs[(size_t) (t - lo)]);
        }
        // F682: the data section starts right after the HEADER, aligned - data_start
        // is where the tensor-offset table says the first tensor begins, so it must be
        // derived from the header length just written (o.tellp()), NEVER from cur (the
        // running tensor-byte total): deriving it from cur put the declared offsets
        // hundreds of MB past the actual data, and every read-back landed in the gap.
        const uint64_t data_start = align_up((uint64_t) o.tellp(), g_align);
        // pad to the data start
        for (uint64_t p = (uint64_t) o.tellp(); p < data_start; ++p) o.put('\0');
        // F682: tensor bytes are copied at their DECLARED offsets - a tensor whose
        // bytes are not a multiple of g_align is followed by alignment padding, and
        // the next tensor must land exactly on its declared off (streaming without
        // the pads made the read-back misaligned and the ids diverged).
        for (int t = lo; t < hi; ++t) {
            const Tensor& t0 = g_tensors[(size_t) t];
            const uint64_t want = data_start + offs[(size_t) (t - lo)];
            const uint64_t at = (uint64_t) o.tellp();
            if (at < want) {
                for (uint64_t q = at; q < want; ++q) o.put('\0');
            } else if (at > want) {
                g_error = "make_two_shards: layout overlap on " + t0.name;
                return false;
            }
            sin.clear();
            sin.seekg((std::streamoff) (g_data_start + t0.off));
            if (!sin) { g_error = "make_two_shards: seek failed on " + t0.name; return false; }
            std::vector<char> blk((size_t) t0.bytes);
            sin.read(blk.data(), (std::streamsize) t0.bytes);
            if ((uint64_t) sin.gcount() != t0.bytes) { g_error = "make_two_shards: short tensor read on " + t0.name; return false; }
            o.write(blk.data(), (std::streamsize) t0.bytes);
        }
        if (!o) { g_error = "make_two_shards: write failed on " + shard_name(k); return false; }
    }
    g_error = shard_name(0);
    return true;
}

}  // namespace gguf

using namespace php;

php::Bool php_gguf_load(php::Str path) {
    return php::Bool(gguf::load(std::string(path.data(), path.length())));
}
php::Bool php_gguf_load_sharded(php::Str first) {
    return php::Bool(gguf::load_sharded(std::string(first.data(), first.length())));
}
php::Str php_gguf_make_shards(php::Str src, php::Str dir) {
    const std::string s((const char*) src.data(), src.length());
    const std::string d((const char*) dir.data(), dir.length());
    return php::Str(gguf::make_two_shards(s, d) ? gguf::error() : std::string());
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
php::Str php_gguf_token_decode(php::Int id) { return php::Str(gguf::token_decode((int) id)); }
// Token ids whose decoded text is non-empty, contains no control marker ("<|"), and uses only
// bytes from `charset` after GPT-2 byte-level decoding. This is the vocabulary side of
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
        const std::string dec = gguf::token_decode((int) i);
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
