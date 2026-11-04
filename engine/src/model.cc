// PHP-facing bridge for the forward pass, compiled by cl.
// Tokens arrive as "785,6722,..." and results leave as scalars: php::Array indexing
// would need phpx APIs that the docs don't pin down, and a string is already proven.
#include <phpx.h>

#include <cstdint>
#include <cstdlib>
#include <cctype>
#include <filesystem>
#include <string>
#include <vector>

namespace model {
std::string last_error();
std::string residency_report();
double top_logit();
double second_logit();
double expect_logit();
double logsumexp();
double token_prob(int id, double temp);
double rand01();
int sample_excluding(int exclude, double temp);
int expect_rank();
int trace_count();
double trace_at(int i);
int stage_count();
double stage_at(int i);
std::string plan(int backend, int ctx_tokens);
void set_budget_gib(double gib);
void set_kv_f16(int on);
int kv_f16();
void set_weight_prec(int p);
int weight_prec();
int session_start(int backend, const std::vector<int>& ids);
int session_next(int backend, double temp, double top_p);
void session_seed(uint64_t seed);
int session_len();
bool session_save(int slot);
bool session_restore(int slot);
int session_next_allowed(int backend, const std::vector<int>& allowed);
int eos_id();
std::vector<int> spec_generate(int backend, std::vector<int> ids, int n_new, int k);
std::vector<int> spec_tree_generate(int backend, std::vector<int> ids, int n_new, int bins, int depth);
std::string spec_stats();
bool cache_store(int backend, const std::vector<int>& ids, const std::string& path);
int cache_prefill(int backend, const std::vector<int>& ids, const std::string& path);
std::string device_fingerprint(int backend);
double kv_cache_gib();
void set_split(int backend, int layer);
std::string split_state();
std::vector<double> prefill_top1(int backend, const std::vector<int>& tokens, int expect_id, int pos);
std::vector<int> generate(int backend, std::vector<int> ids, int n_new);
std::vector<int> generate_sampled(int backend, std::vector<int> ids, int n_new,
                                  double temp, double top_p, uint64_t seed);
int nucleus_size();
double gen_prefill_ms();
double gen_step_ms();
}  // namespace model

using namespace php;

namespace {
std::vector<int> parse_ids(const std::string& csv) {
    std::vector<int> out;
    size_t pos = 0;
    while (pos < csv.size()) {
        size_t comma = csv.find(',', pos);
        if (comma == std::string::npos) comma = csv.size();
        const std::string tok = csv.substr(pos, comma - pos);
        if (!tok.empty()) out.push_back((int) strtoll(tok.c_str(), nullptr, 10));
        pos = comma + 1;
    }
    return out;
}
}  // namespace

php::Str php_model_last_error() { return php::Str(model::last_error()); }
php::Str php_model_residency_report() { return php::Str(model::residency_report()); }
php::Float php_model_top_logit() { return php::Float(model::top_logit()); }
php::Float php_model_second_logit() { return php::Float(model::second_logit()); }
php::Float php_model_expect_logit() { return php::Float(model::expect_logit()); }
php::Float php_model_logsumexp() { return php::Float(model::logsumexp()); }
php::Float php_model_token_prob(php::Int id, php::Float temp) { return php::Float(model::token_prob((int) id, (double) temp)); }
php::Float php_model_rand01() { return php::Float(model::rand01()); }
php::Int php_model_sample_excluding(php::Int exclude, php::Float temp) { return php::Int(model::sample_excluding((int) exclude, (double) temp)); }
php::Int php_model_expect_rank() { return php::Int(model::expect_rank()); }
php::Float php_model_gen_prefill_ms() { return php::Float(model::gen_prefill_ms()); }
php::Float php_model_gen_step_ms() { return php::Float(model::gen_step_ms()); }
php::Int php_model_trace_count() { return php::Int(model::trace_count()); }
php::Float php_model_trace_at(php::Int i) { return php::Float(model::trace_at((int) i)); }
php::Int php_model_stage_count() { return php::Int(model::stage_count()); }
php::Float php_model_stage_at(php::Int i) { return php::Float(model::stage_at((int) i)); }
php::Str php_model_plan(php::Int backend, php::Int ctx) {
    return php::Str(model::plan((int) backend, (int) ctx));
}
php::Int php_model_set_budget_gib(php::Float gib) {
    model::set_budget_gib((double) gib);
    return php::Int(1);
}
php::Int php_model_set_kv_f16(php::Int on) {
    model::set_kv_f16((int) on);
    return php::Int(model::kv_f16());
}
php::Float php_model_kv_cache_gib() { return php::Float(model::kv_cache_gib()); }
php::Int php_model_set_weight_prec(php::Int p) {
    model::set_weight_prec((int) p);
    return php::Int(model::weight_prec());
}
php::Int php_model_weight_prec() { return php::Int(model::weight_prec()); }
php::Int php_model_session_start(php::Int backend, php::Str tokens) {
    const std::string csv(tokens.data(), tokens.length());
    return php::Int(model::session_start((int) backend, parse_ids(csv)));
}
php::Int php_model_session_next(php::Int backend, php::Float temp, php::Float top_p) {
    return php::Int(model::session_next((int) backend, (double) temp, (double) top_p));
}
php::Int php_model_session_len() { return php::Int(model::session_len()); }
php::Int php_model_session_seed(php::Int seed) {
    model::session_seed((uint64_t)(int64_t) seed);
    return php::Int(1);
}
php::Int php_model_session_save(php::Int slot) {
    return php::Int(model::session_save((int) slot) ? 1 : 0);
}
php::Int php_model_session_restore(php::Int slot) {
    return php::Int(model::session_restore((int) slot) ? 1 : 0);
}
php::Int php_model_session_next_allowed(php::Int backend, php::Str allowed) {
    const std::string csv(allowed.data(), allowed.length());
    return php::Int(model::session_next_allowed((int) backend, parse_ids(csv)));
}
php::Int php_model_eos_id() { return php::Int(model::eos_id()); }
php::Str php_model_spec_generate(php::Int backend, php::Str tokens, php::Int n_new, php::Int k) {
    const std::string csv(tokens.data(), tokens.length());
    const std::vector<int> out = model::spec_generate((int) backend, parse_ids(csv),
                                                     (int) n_new, (int) k);
    std::string s;
    for (size_t i = 0; i < out.size(); ++i) {
        if (i) s += ",";
        s += std::to_string(out[i]);
    }
    return php::Str(s);
}
php::Str php_model_spec_stats() { return php::Str(model::spec_stats()); }
php::Str php_model_spec_tree(php::Int backend, php::Str tokens, php::Int n_new, php::Int bins,
                             php::Int depth) {
    const std::string csv(tokens.data(), tokens.length());
    const std::vector<int> out = model::spec_tree_generate(
        (int) backend, parse_ids(csv), (int) n_new, (int) bins, (int) depth);
    std::string s;
    for (size_t i = 0; i < out.size(); ++i) {
        if (i) s += ",";
        s += std::to_string(out[i]);
    }
    return php::Str(s);
}
php::Int php_model_cache_store(php::Int backend, php::Str tokens, php::Str path) {
    const std::string csv(tokens.data(), tokens.length());
    const std::string p(path.data(), path.length());
    return php::Int(model::cache_store((int) backend, parse_ids(csv), p) ? 1 : 0);
}
php::Int php_model_cache_prefill(php::Int backend, php::Str tokens, php::Str path) {
    const std::string csv(tokens.data(), tokens.length());
    const std::string p(path.data(), path.length());
    return php::Int(model::cache_prefill((int) backend, parse_ids(csv), p));
}
php::Str php_model_device_fingerprint(php::Int backend) {
    return php::Str(model::device_fingerprint((int) backend));
}
php::Int php_model_set_split(php::Int backend, php::Int layer) {
    model::set_split((int) backend, (int) layer);
    return php::Int(1);
}
php::Str php_model_split_state() { return php::Str(model::split_state()); }

// Directory-style model folder: "" when the path is not a directory, "!" when it is one
// without exactly one .gguf, otherwise the single .gguf's path. Directory listing is not
// available on the PHP side, so the scan lives here.
// Stable prefix-cache bucket: FNV-1a over the first 256 prompt characters, mod `buckets`.
php::Int php_model_cache_key(php::Str ids, php::Int buckets) {
    const std::string s((const char*) ids.data(), ids.length());
    uint32_t h = 2166136261u;
    for (size_t i = 0; i < s.size() && i < 256; ++i) {
        h ^= (unsigned char) s[i];
        h *= 16777619u;
    }
    const int b = buckets > 0 ? (int) (h % (uint32_t) buckets) : 0;
    return php::Int(b);
}

// "name|bytes" lines for regular files in dir whose name ends with ext (case-insensitive).
php::Str php_model_dir_files(php::Str dir, php::Str ext) {
    namespace fs = std::filesystem;
    std::error_code ec;
    const fs::path d(std::string(dir.data(), dir.length()));
    std::string e((const char*) ext.data(), ext.length());
    for (size_t i = 0; i < e.size(); ++i) e[i] = (char) std::tolower((unsigned char) e[i]);
    std::string out;
    if (!fs::is_directory(d, ec)) return php::Str(out);
    for (const auto& ent : fs::directory_iterator(d, ec)) {
        if (!ent.is_regular_file(ec)) continue;
        const std::string name = ent.path().filename().string();
        std::string low = name;
        for (size_t i = 0; i < low.size(); ++i) low[i] = (char) std::tolower((unsigned char) low[i]);
        if (low.size() < e.size() || low.compare(low.size() - e.size(), e.size(), e) != 0) continue;
        std::error_code ec2;
        const auto sz = fs::file_size(ent.path(), ec2);
        out += name + "|" + std::to_string(ec2 ? 0ull : (unsigned long long) sz) + "\n";
    }
    return php::Str(out);
}

php::Str php_model_dir_gguf(php::Str dir) {
    namespace fs = std::filesystem;
    std::error_code ec;
    const fs::path d(std::string(dir.data(), dir.length()));
    if (!fs::is_directory(d, ec)) return php::Str("");
    std::string found;
    int n = 0;
    for (const auto& e : fs::directory_iterator(d, ec)) {
        if (!e.is_regular_file(ec)) continue;
        std::string ext = e.path().extension().string();
        for (size_t i = 0; i < ext.size(); ++i) ext[i] = (char) std::tolower((unsigned char) ext[i]);
        if (ext == ".gguf") {
            found = e.path().string();
            ++n;
        }
    }
    if (ec || n != 1) return php::Str("!" + std::to_string(n));
    return php::Str(found);
}

php::Int php_model_prefill(php::Int backend, php::Str tokens, php::Int expect_id) {
    const std::string csv(tokens.data(), tokens.length());
    const std::vector<double> r = model::prefill_top1((int) backend, parse_ids(csv), (int) expect_id, 0);
    return php::Int(r.empty() ? -1 : (int64_t) r[0]);
}

// Greedy generation, returned as a comma-separated id list (prompt ids first).
php::Str php_model_generate(php::Int backend, php::Str tokens, php::Int n_new) {
    const std::string csv(tokens.data(), tokens.length());
    const std::vector<int> out = model::generate((int) backend, parse_ids(csv), (int) n_new);
    std::string s;
    for (size_t i = 0; i < out.size(); ++i) {
        if (i) s += ",";
        s += std::to_string(out[i]);
    }
    return php::Str(s);
}

// Generation with temperature + top-p (nucleus). seed makes the run reproducible.
php::Str php_model_generate_sampled(php::Int backend, php::Str tokens, php::Int n_new,
                                    php::Float temp, php::Float top_p, php::Int seed) {
    const std::string csv(tokens.data(), tokens.length());
    const std::vector<int> out = model::generate_sampled(
        (int) backend, parse_ids(csv), (int) n_new, (double) temp, (double) top_p,
        (uint64_t) seed);
    std::string s;
    for (size_t i = 0; i < out.size(); ++i) {
        if (i) s += ",";
        s += std::to_string(out[i]);
    }
    return php::Str(s);
}

php::Int php_model_nucleus_size() { return php::Int(model::nucleus_size()); }
