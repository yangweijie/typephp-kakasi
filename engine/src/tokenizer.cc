// Byte-level BPE encoder (gpt2/qwen2 scheme), compiled by cl.
// The pre-tokenizer transcribes llama.cpp's qwen2 splitter regex (src/llama-vocab.cpp:289)
// alternative by alternative, and the byte->symbol map reproduces gpt2 bytes_to_unicode() by
// rule, so newlines, tabs and Latin-1 bytes encode too. Remaining gap vs llama.cpp: \p{L}
// and \p{N} are ASCII-only classes here (non-ASCII bytes are lumped in as letters, which keeps
// a CJK run in one piece like \p{L}+ does, but does not know about other categories).
// Correctness is asserted against llama-tokenize, case by case, in main.php.
#include <phpx.h>

#include "gguf.h"

#include <algorithm>
#include <cctype>
#include <cstdint>
#include <cstdlib>
#include <string>
#include <unordered_map>
#include <vector>

namespace tok {
namespace {
    std::string g_error;
    std::unordered_map<std::string, int> g_rank;   // "A B" -> merge priority
    std::unordered_map<std::string, int> g_id;     // token text -> id
    // Control tokens (vocab type 3), longest first. They are matched literally and emitted as
    // single ids: BPE would otherwise shred "<|end|>" into sub-word pieces.
    std::vector<std::pair<std::string, int>> g_ctrl;
    bool g_ready = false;

    // Character classes of llama.cpp's qwen2 pre-tokenizer regex, at byte level.
    // Non-ASCII bytes count as letters: a run of multi-byte UTF-8 codepoints then stays in one
    // piece, which is what \p{L}+ does for CJK. Precise Unicode general categories would need
    // the full category tables, which are not ported; see the scope note at the top.
    enum Ctype { CT_SPACE, CT_NL, CT_DIGIT, CT_LETTER, CT_OTHER };

    Ctype ctype(unsigned char c) {
        if (c == '\n' || c == '\r') return CT_NL;
        if (c == ' ' || c == '\t' || c == '\v' || c == '\f') return CT_SPACE;
        if (c >= '0' && c <= '9') return CT_DIGIT;
        if ((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || c >= 0x80) return CT_LETTER;
        return CT_OTHER;
    }

    bool is_ws(unsigned char c) { return ctype(c) == CT_SPACE || ctype(c) == CT_NL; }

    // \p{L} excluding the \r\n carve-out the regex makes, i.e. [^\r\n\p{L}\p{N}] of alt2.
    bool not_letter_not_digit_not_nl(unsigned char c) {
        const Ctype t = ctype(c);
        return t != CT_LETTER && t != CT_DIGIT && t != CT_NL;
    }

    void collect_control_tokens();

    bool build() {
        g_rank.clear();
        g_id.clear();
        g_ready = false;
        const int nm = gguf::arr_size("tokenizer.ggml.merges");
        const int nv = gguf::arr_size("tokenizer.ggml.tokens");
        if (nm <= 0 || nv <= 0) { g_error = "vocab arrays missing from model"; return false; }
        for (int i = 0; i < nv; ++i) {
            const std::string t = gguf::arr_at("tokenizer.ggml.tokens", i);
            if (!t.empty() && g_id.find(t) == g_id.end()) g_id[t] = i;
        }
        for (int i = 0; i < nm; ++i) {
            const std::string m = gguf::arr_at("tokenizer.ggml.merges", i);
            const size_t sp = m.find(' ');
            if (sp == std::string::npos) { g_error = "malformed merge: " + m; return false; }
            // rank key is the concatenated pair, matching the merge file's two symbols
            const std::string key = m.substr(0, sp) + m.substr(sp + 1);
            if (g_rank.find(key) == g_rank.end()) g_rank[key] = i;
        }
        g_ready = true;
        collect_control_tokens();
        return true;
    }

    // Populate g_ctrl from the vocab's token_type, not from a name pattern.
    void collect_control_tokens() {
        g_ctrl.clear();
        const std::vector<std::string>* names = gguf::arr_ptr("tokenizer.ggml.tokens");
        const std::vector<std::string>* types = gguf::arr_ptr("tokenizer.ggml.token_type");
        if (!names || !types) return;
        const size_t n = std::min(names->size(), types->size());
        for (size_t i = 0; i < n; ++i) {
            if (atoi((*types)[i].c_str()) != 3) continue;  // LLAMA_TOKEN_TYPE_CONTROL
            const std::string& s = (*names)[i];
            if (!s.empty()) g_ctrl.push_back({s, (int) i});
        }
        std::sort(g_ctrl.begin(), g_ctrl.end(),
                  [](const std::pair<std::string, int>& a, const std::pair<std::string, int>& b) {
                      return a.first.size() > b.first.size();
                  });
    }

    // alt1: '(?i:'s|'t|'re|'ve|'m|'ll|'d). Returns the length matched at i, or 0.
    size_t contraction_len(const std::string& t, size_t i, size_t n) {
        if (i >= n || t[i] != '\x27') return 0;
        static const char* suf[] = {"ll", "re", "ve", "s", "t", "m", "d"};
        static const int   slen[] = {2, 2, 2, 1, 1, 1, 1};
        for (int k = 0; k < 7; ++k) {
            const int L = slen[k];
            if (i + 1 + L > n) continue;
            bool ok = true;
            for (int c = 0; c < L; ++c) {
                const char got = (char) std::tolower((unsigned char) t[i + 1 + c]);
                if (got != suf[k][c]) { ok = false; break; }
            }
            if (!ok) continue;
            // the regex has no word-boundary guard; llama.cpp applies it the same way
            return (size_t) 1 + L;
        }
        return 0;
    }

    // llama.cpp qwen2 splitter, src/llama-vocab.cpp:289, transcribed alternative by
    // alternative in regex order so the first match at each position wins:
    //   1 (?:'[sStT]|'[rR][eE]|'[vV][eE]|'[mM]|'[lL][lL]|'[dD])
    //   2 [^\r\n\p{L}\p{N}]?\p{L}+
    //   3 \p{N}{1,3}
    //   4  ?[^\s\p{L}\p{N}]+[\r\n]*
    //   5 \s*[\r\n]+
    //   6 \s+(?!\S)
    //   7 \s+
    // Only these alternatives need explicit backtracking: 5 (greedy \s* then [\r\n]+) and
    // 6 (the (?!\S) guard, which caps the run at the last space before a non-space).
    std::vector<std::string> pre_split(const std::string& text) {
        std::vector<std::string> words;
        const size_t n = text.size();
        size_t i = 0;
        while (i < n) {
            size_t len = contraction_len(text, i, n);
            if (len) { words.push_back(text.substr(i, len)); i += len; continue; }

            // 2: optional single non-{\r\n,L,N} char, then one or more letters
            {
                size_t j = i;
                if (not_letter_not_digit_not_nl((unsigned char) text[i])) ++j;
                size_t k = j;
                while (k < n && ctype((unsigned char) text[k]) == CT_LETTER) ++k;
                if (k > j) { words.push_back(text.substr(i, k - i)); i = k; continue; }
            }

            // 3: \p{N}{1,3}
            if (ctype((unsigned char) text[i]) == CT_DIGIT) {
                size_t j = i;
                while (j < n && j - i < 3 && ctype((unsigned char) text[j]) == CT_DIGIT) ++j;
                words.push_back(text.substr(i, j - i)); i = j; continue;
            }

            // 4: optional single space, then a run of printing non-alphanumeric, then newlines
            {
                size_t j = (text[i] == ' ') ? i + 1 : i;
                size_t k = j;
                while (k < n) {
                    const Ctype t = ctype((unsigned char) text[k]);
                    if (t == CT_SPACE || t == CT_LETTER || t == CT_DIGIT) break;
                    ++k;
                }
                if (k > j) {
                    while (k < n && ctype((unsigned char) text[k]) == CT_NL) ++k;
                    words.push_back(text.substr(i, k - i)); i = k; continue;
                }
            }

            // whitespace-only remains: 5, 6, 7
            {
                size_t run = i;
                while (run < n && is_ws((unsigned char) text[run])) ++run;
                if (run > i) {
                    // 5: \s*[\r\n]+ -> the run up to and including its last contiguous newline tail
                    size_t tail = run;
                    while (tail > i && (text[tail - 1] == '\n' || text[tail - 1] == '\r')) --tail;
                    if (tail < run) { words.push_back(text.substr(i, run - i)); i = run; continue; }
                    // 6: \s+(?!\S) -> all but the final space when a word follows
                    const size_t drop = (run < n) ? 1 : 0;
                    const size_t take = run - i - drop;
                    if (take >= 1) { words.push_back(text.substr(i, take)); i += take; continue; }
                    // 7: \s+ (one space, and here it cannot happen alone before a word)
                    words.push_back(text.substr(i, run - i)); i = run; continue;
                }
            }
            // unreachable for well-formed input, but never spin
            words.push_back(text.substr(i, 1));
            ++i;
        }
        return words;
    }


    // gpt2 bytes_to_unicode(), reproduced as a rule instead of a table: bytes 33-126,
    // 161-172 and 174-255 stand for themselves; every other byte b gets the codepoint
    // 256 + (its index among the non-visible bytes listed in ascending order). That places
    // 0x20 at U+0120 ('Ġ') and '\n' at U+010A ('Ċ'), matching the vocab strings in the GGUF.
    std::string symbol(unsigned char b) {
        unsigned int cp;
        if ((b >= 33 && b <= 126) || (b >= 161 && b <= 172) || b >= 174) {
            cp = b;
        } else if (b <= 32) {
            cp = 256u + b;
        } else if (b >= 127 && b <= 160) {
            cp = 256u + 33u + (b - 127u);
        } else {  // b == 173, the only gap left
            cp = 256u + 67u;
        }
        if (cp < 0x80) return std::string(1, (char) cp);
        if (cp < 0x800) {
            std::string s;
            s.push_back((char) (0xC0 | (cp >> 6)));
            s.push_back((char) (0x80 | (cp & 0x3F)));
            return s;
        }
        std::string s;
        s.push_back((char) (0xE0 | (cp >> 12)));
        s.push_back((char) (0x80 | ((cp >> 6) & 0x3F)));
        s.push_back((char) (0x80 | (cp & 0x3F)));
        return s;
    }


    std::vector<std::string> to_symbols(const std::string& piece) {
        std::vector<std::string> syms;
        for (unsigned char c : piece) {
            const std::string s = symbol(c);
            if (s.empty()) return {};
            syms.push_back(s);
        }
        return syms;
    }

    void merge_once(std::vector<std::string>& syms) {
        while (syms.size() >= 2) {
            int best = -1;
            int best_rank = INT32_MAX;
            for (size_t i = 0; i + 1 < syms.size(); ++i) {
                auto it = g_rank.find(syms[i] + syms[i + 1]);
                if (it != g_rank.end() && it->second < best_rank) {
                    best_rank = it->second;
                    best = (int) i;
                }
            }
            if (best < 0) return;
            syms[(size_t) best] += syms[(size_t) best + 1];
            syms.erase(syms.begin() + best + 1);
        }
    }

    // BPE over one plain-text span. The pre-split must happen here: merging the whole span as
    // a single symbol sequence coincides with the per-word result on most text and only
    // diverges where two pieces both start with the space symbol.
    bool encode_span(const std::string& piece, std::vector<int>& ids) {
        for (const std::string& word : pre_split(piece)) {
            std::vector<std::string> syms = to_symbols(word);
            if (syms.empty()) { g_error = "unsupported byte in input"; return false; }
            merge_once(syms);
            for (const auto& s : syms) {
                auto it = g_id.find(s);
                if (it == g_id.end()) { g_error = "symbol not in vocab: " + s; return false; }
                ids.push_back(it->second);
            }
        }
        return true;
    }
}  // namespace

std::string last_error() { return g_error; }

std::vector<int> encode(const std::string& text) {

    g_error.clear();
    if (!g_ready && !build()) return {};
    std::vector<int> ids;
    size_t pos = 0;
    while (pos <= text.size()) {
        // Earliest control-token hit wins; at the same offset the longest match wins.
        size_t at = std::string::npos;
        size_t len = 0;
        int id = -1;
        for (const auto& ct : g_ctrl) {
            const size_t f = text.find(ct.first, pos);
            if (f == std::string::npos) continue;
            if (f < at || (f == at && ct.first.size() > len)) { at = f; len = ct.first.size(); id = ct.second; }
        }
        if (at == std::string::npos) {
            if (!encode_span(text.substr(pos), ids)) return {};
            break;
        }
        if (!encode_span(text.substr(pos, at - pos), ids)) return {};
        ids.push_back(id);
        pos = at + len;
    }
    return ids;
}

// ChatML markers assembled at runtime so this source never carries a literal control token
// (raw markers trip up editors, diffs and greps).
const std::string IM_START = std::string("<|") + "im_start" + "|>";
const std::string IM_END = std::string("<|") + "im_end" + "|>";

// One turn of the model's chat template: start-marker, role, newline, content, end-marker,
// newline. A full Jinja renderer is out of scope, so rather than silently applying our
// rendering to any model, chat_template_matches() gates it on the model speaking this dialect.
std::string render_chat(const std::string& role, const std::string& content) {
    return IM_START + role + "\n" + content + IM_END + "\n";
}

// Just the opening marker of a turn: start, role, newline. The template ends a chat prompt with
// this for the assistant turn; without it the model keeps writing the NEXT turn's header.
std::string render_chat_open(const std::string& role) {
    return IM_START + role + "\n";
}

// Tool turns, transcribed from tokenizer.chat_template (Qwen2.5 dialect):
//   assistant: <|im_start|>assistant\n<tool_call>\n{"name": N, "arguments": A}\n</tool_call><|im_end|>\n
//   result:    <|im_start|>user\n<tool_response>\nCONTENT\n</tool_response><|im_end|>\n
// The template groups consecutive results into one user turn; per-message rendering is what the
// API layer does today, which differs only for parallel results (documented, not silent).
// One assistant turn can carry several calls: <|im_start|>assistant, then one <tool_call> block
// per call (no separator beyond a newline), then <|im_end|>.
std::string render_tool_calls(const std::vector<std::pair<std::string, std::string>>& calls) {
    std::string out = IM_START + "assistant";
    for (size_t i = 0; i < calls.size(); ++i) {
        out += "\n<tool_call>\n{\"name\": \"" + calls[i].first + "\", \"arguments\": " + calls[i].second +
               "}\n</tool_call>";
    }
    return out + IM_END + "\n";
}

std::string render_tool_call(const std::string& name, const std::string& args) {
    std::vector<std::pair<std::string, std::string>> one;
    one.push_back(std::make_pair(name, args));
    return render_tool_calls(one);
}

std::string render_tool_result(const std::string& content) {
    return IM_START + "user\n<tool_response>\n" + content + "\n</tool_response>" + IM_END + "\n";
}

bool chat_template_matches() {
    const std::string t = gguf::meta_str("tokenizer.chat_template");
    return t.find(IM_START) != std::string::npos &&
           t.find(IM_END) != std::string::npos &&
           t.find(IM_START + "assistant") != std::string::npos;
}

}  // namespace tok

using namespace php;

php::Str php_tok_last_error() { return php::Str(tok::last_error()); }

namespace {
std::string join_ids(const std::vector<int>& ids) {
    std::string out;
    for (size_t i = 0; i < ids.size(); ++i) {
        if (i) out += ",";
        out += std::to_string(ids[i]);
    }
    return out;
}
std::vector<std::string> split_sep(const std::string& s, const std::string& sep) {
    std::vector<std::string> out;
    size_t pos = 0;
    while (true) {
        const size_t hit = s.find(sep, pos);
        if (hit == std::string::npos) {
            out.push_back(s.substr(pos));
            break;
        }
        out.push_back(s.substr(pos, hit - pos));
        pos = hit + sep.size();
    }
    return out;
}
}  // namespace

php::Str php_tok_encode(php::Str text) {
    const std::string s((const char*) text.data(), text.length());
    return php::Str(join_ids(tok::encode(s)));
}

// Encode one chat turn straight from role/content, so callers never hand-write markers.
php::Str php_tok_chat(php::Str role, php::Str content) {
    const std::string r((const char*) role.data(), role.length());
    const std::string c((const char*) content.data(), content.length());
    return php::Str(join_ids(tok::encode(tok::render_chat(r, c))));
}

php::Int php_tok_chat_template_matches() { return php::Int(tok::chat_template_matches() ? 1 : 0); }

// Encode a turn header only (start marker + role + newline), used to open the assistant turn.
php::Str php_tok_chat_open(php::Str role) {
    const std::string r((const char*) role.data(), role.length());
    return php::Str(join_ids(tok::encode(tok::render_chat_open(r))));
}

php::Str php_tok_tool_call(php::Str name, php::Str args) {
    const std::string n((const char*) name.data(), name.length());
    const std::string a((const char*) args.data(), args.length());
    return php::Str(join_ids(tok::encode(tok::render_tool_call(n, a))));
}

php::Str php_tok_tool_result(php::Str content) {
    const std::string c((const char*) content.data(), content.length());
    return php::Str(join_ids(tok::encode(tok::render_tool_result(c))));
}

// Parallel calls: names and arguments arrive as two "@@TOOLCALL@@"-separated blobs, same order.
php::Str php_tok_tool_calls(php::Str names, php::Str args) {
    const std::string ns((const char*) names.data(), names.length());
    const std::string as((const char*) args.data(), args.length());
    const std::vector<std::string> nv = split_sep(ns, "@@TOOLCALL@@");
    const std::vector<std::string> av = split_sep(as, "@@TOOLCALL@@");
    std::vector<std::pair<std::string, std::string>> calls;
    for (size_t i = 0; i < nv.size() && i < av.size(); ++i) {
        calls.push_back(std::make_pair(nv[i], av[i]));
    }
    return php::Str(join_ids(tok::encode(tok::render_tool_calls(calls))));
}
