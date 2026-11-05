// sampler_bench.cpp - what the temperature>0 sampling path actually costs, and which variant
// keeps the distribution while dropping the sort (F362).
//
// Why this probe exists: the served API samples at temperature>0, and the engine's sampler
// (engine/src/model_sycl.cc, sample_from) sorts the WHOLE 151936-entry vocabulary for every
// token, even when top_p=1.0 - and then the nucleus is the whole vocabulary, so the order is
// only needed to walk the cumulative. The served measurement (F361) put that path at ~15ms per
// token per stream, which is why two concurrent streams scale at 1.45x while the same build
// reaches 1.75x at temperature 0.
//
// Three implementations are compared on the SAME logits:
//   A  current : fresh p/order vectors + std::sort over indices (what the engine does today)
//   B  packed  : reused buffers + std::sort over packed (p,id) pairs
//   C  select  : reused buffers + quickselect-with-sums (no full sort at all)
//
// The property that matters is the DISTRIBUTION: A, B and C must sample the same token
// frequencies. The exact token for a given seed may differ on ties (equal probabilities), which
// is why the check here is on frequencies and not on ids.
//
// Usage: sampler_bench [draws]        (default 1000)
#include <algorithm>
#include <chrono>
#include <cmath>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <random>
#include <vector>

namespace {

struct Pair {
    double p;
    int id;
};

bool by_p_desc(const Pair& a, const Pair& b) { return a.p > b.p; }

uint64_t rng_state = 0x9E3779B97F4A7C15ull;
double next_unit() {
    rng_state ^= rng_state << 13;
    rng_state ^= rng_state >> 7;
    rng_state ^= rng_state << 17;
    return (double) (rng_state >> 11) * (1.0 / 9007199254740992.0);
}

// A: the engine's current shape - two fresh vectors, std::sort over indices.
int sample_A(const std::vector<float>& lg, double temp, double top_p) {
    const int n = (int) lg.size();
    int argmax = 0;
    float mx = lg[0];
    for (int i = 1; i < n; ++i) {
        if (lg[i] > mx) { mx = lg[i]; argmax = i; }
    }
    if (temp <= 0.0) return argmax;
    std::vector<double> p((size_t) n);
    double z = 0.0;
    for (int i = 0; i < n; ++i) {
        const double v = std::exp(((double) lg[i] - (double) mx) / temp);
        p[(size_t) i] = v;
        z += v;
    }
    if (!(z > 0.0)) return argmax;
    const double inv_z = 1.0 / z;
    std::vector<int> order((size_t) n);
    for (int i = 0; i < n; ++i) order[(size_t) i] = i;
    std::sort(order.begin(), order.end(), [&](int a, int b) { return p[(size_t) a] > p[(size_t) b]; });
    const double target = (top_p > 0.0 && top_p < 1.0) ? top_p : 1.0;
    int keep = 0;
    double cum = 0.0;
    for (int i = 0; i < n; ++i) {
        cum += p[(size_t) order[(size_t) i]] * inv_z;
        ++keep;
        if (cum >= target) break;
    }
    const double u = next_unit() * cum;
    double acc = 0.0;
    for (int i = 0; i < keep; ++i) {
        acc += p[(size_t) order[(size_t) i]] * inv_z;
        if (u < acc) return order[(size_t) i];
    }
    return order[(size_t) keep - 1];
}

// B: same algorithm, but the sort works on packed (p,id) pairs and the buffers are reused.
int sample_B(const std::vector<float>& lg, double temp, double top_p, std::vector<Pair>& buf) {
    const int n = (int) lg.size();
    int argmax = 0;
    float mx = lg[0];
    for (int i = 1; i < n; ++i) {
        if (lg[i] > mx) { mx = lg[i]; argmax = i; }
    }
    if (temp <= 0.0) return argmax;
    buf.resize((size_t) n);
    double z = 0.0;
    for (int i = 0; i < n; ++i) {
        const double v = std::exp(((double) lg[i] - (double) mx) / temp);
        buf[(size_t) i].p = v;
        buf[(size_t) i].id = i;
        z += v;
    }
    if (!(z > 0.0)) return argmax;
    const double inv_z = 1.0 / z;
    std::sort(buf.begin(), buf.end(), by_p_desc);
    const double target = (top_p > 0.0 && top_p < 1.0) ? top_p : 1.0;
    int keep = 0;
    double cum = 0.0;
    for (int i = 0; i < n; ++i) {
        cum += buf[(size_t) i].p * inv_z;
        ++keep;
        if (cum >= target) break;
    }
    const double u = next_unit() * cum;
    double acc = 0.0;
    for (int i = 0; i < keep; ++i) {
        acc += buf[(size_t) i].p * inv_z;
        if (u < acc) return buf[(size_t) i].id;
    }
    return buf[(size_t) keep - 1].id;
}

// C: no full sort. The crossing token of the descending cumulative is found by a quickselect
// that carries the sum of the probability mass already known to be above the active range, so
// the probability accounting stays exact (ties may be broken differently, which does not change
// the distribution).
int select_sample(std::vector<Pair>& v, int lo, int hi, double acc, double u) {
    while (lo < hi) {
        const int mid = lo + (hi - lo) / 2;
        std::nth_element(v.begin() + lo, v.begin() + mid, v.begin() + hi, by_p_desc);
        double top = 0.0;
        for (int i = lo; i <= mid; ++i) top += v[(size_t) i].p;
        if (acc + top > u) {
            hi = mid;
        } else {
            acc += top;
            lo = mid + 1;
        }
    }
    return v[(size_t) lo].id;
}

int sample_C(const std::vector<float>& lg, double temp, double top_p, std::vector<Pair>& buf) {
    const int n = (int) lg.size();
    int argmax = 0;
    float mx = lg[0];
    for (int i = 1; i < n; ++i) {
        if (lg[i] > mx) { mx = lg[i]; argmax = i; }
    }
    if (temp <= 0.0) return argmax;
    buf.resize((size_t) n);
    double z = 0.0;
    for (int i = 0; i < n; ++i) {
        const double v = std::exp(((double) lg[i] - (double) mx) / temp);
        buf[(size_t) i].p = v;
        buf[(size_t) i].id = i;
        z += v;
    }
    if (!(z > 0.0)) return argmax;
    const double inv_z = 1.0 / z;
    const double target = (top_p > 0.0 && top_p < 1.0) ? top_p : 1.0;
    if (target >= 1.0) {
        // The whole vocabulary is the nucleus, so the quantile alone decides the token.
        const double u = next_unit() * z;
        return select_sample(buf, 0, n - 1, 0.0, u);
    }
    // top_p < 1 needs the ordered prefix to know where the nucleus ends: fall back to the sort.
    std::sort(buf.begin(), buf.end(), by_p_desc);
    double cum = 0.0;
    int keep = 0;
    for (int i = 0; i < n; ++i) {
        cum += buf[(size_t) i].p * inv_z;
        ++keep;
        if (cum >= target) break;
    }
    const double u2 = next_unit() * cum;
    double acc = 0.0;
    for (int i = 0; i < keep; ++i) {
        acc += buf[(size_t) i].p * inv_z;
        if (u2 < acc) return buf[(size_t) i].id;
    }
    return buf[(size_t) keep - 1].id;
}

}  // namespace

int main(int argc, char** argv) {
    const int draws = (argc > 1) ? std::atoi(argv[1]) : 1000;
    const int n = 151936;
    const double temp = 0.9;
    const double top_p = 1.0;

    // Realistic logits: a peaked head over a long tail, plus a few rare exact ties so the tie
    // behaviour is visible instead of assumed away.
    std::mt19937_64 gen(12345);
    std::normal_distribution<double> nd(0.0, 3.0);
    std::vector<float> lg((size_t) n);
    for (int i = 0; i < n; ++i) lg[(size_t) i] = (float) nd(gen);
    lg[7] = 18.0f;
    lg[9] = 17.2f;
    lg[11] = 16.4f;
    lg[13] = 14.9f;
    lg[15] = 14.9f;  // exact tie with 13
    for (int i = 100; i < 140; ++i) lg[(size_t) i] = 5.0f;  // a tied group
    lg[0] = 20.0f;

    std::vector<int> fa, fb, fc;
    std::vector<Pair> buf;
    auto clock = []() { return std::chrono::steady_clock::now(); };
    auto ms = [](std::chrono::steady_clock::time_point a, std::chrono::steady_clock::time_point b) {
        return std::chrono::duration<double, std::milli>(b - a).count();
    };

    rng_state = 0x9E3779B97F4A7C15ull;
    auto t0 = clock();
    for (int d = 0; d < draws; ++d) fa.push_back(sample_A(lg, temp, top_p));
    const double ta = ms(t0, clock());

    rng_state = 0x9E3779B97F4A7C15ull;
    t0 = clock();
    for (int d = 0; d < draws; ++d) fb.push_back(sample_B(lg, temp, top_p, buf));
    const double tb = ms(t0, clock());

    rng_state = 0x9E3779B97F4A7C15ull;
    t0 = clock();
    for (int d = 0; d < draws; ++d) fc.push_back(sample_C(lg, temp, top_p, buf));
    const double tc = ms(t0, clock());

    std::printf("SAMPLER-BENCH n=%d draws=%d temp=%.2f top_p=%.2f\n", n, draws, temp, top_p);
    std::printf("  A current (sort over indices, fresh buffers): %8.3f ms/call\n", ta / draws);
    std::printf("  B packed  (sort over pairs, reused buffers)  : %8.3f ms/call  (x%.2f vs A)\n",
                tb / draws, ta / tb);
    std::printf("  C select  (quickselect, no full sort)         : %8.3f ms/call  (x%.2f vs A)\n",
                tc / draws, ta / tc);

    // Distribution check: the frequency of every sampled token must agree across variants.
    // Compare on the tokens that actually carry mass (the head of the distribution).
    std::vector<int> ids;
    for (int d = 0; d < draws; ++d) {
        ids.push_back(fa[(size_t) d]);
        ids.push_back(fb[(size_t) d]);
        ids.push_back(fc[(size_t) d]);
    }
    std::sort(ids.begin(), ids.end());
    ids.erase(std::unique(ids.begin(), ids.end()), ids.end());
    double worst = 0.0;
    int worst_id = -1;
    for (int id : ids) {
        int ca = 0, cb = 0, cc = 0;
        for (int d = 0; d < draws; ++d) {
            if (fa[(size_t) d] == id) ++ca;
            if (fb[(size_t) d] == id) ++cb;
            if (fc[(size_t) d] == id) ++cc;
        }
        const double pa = (double) ca / draws;
        const double pb = (double) cb / draws;
        const double pc = (double) cc / draws;
        const double base = pa > pb ? pa : pb;
        const double dev = (base > 0.0)
                               ? ((pa > pb ? (pa - pc) : (pb - pc)) / base)
                               : 0.0;
        if (dev < 0) {
            // signed deviation, report magnitude
        }
        if (std::fabs(dev) > worst) { worst = std::fabs(dev); worst_id = id; }
    }
    std::printf("  distinct tokens sampled: %zu, worst frequency deviation vs A/B: %.2f%% (token %d)\n",
                ids.size(), worst * 100.0, worst_id);
    std::printf("  sampling-1.0 sanity: A first=%d B first=%d C first=%d\n", fa[0], fb[0], fc[0]);
    return 0;
}
