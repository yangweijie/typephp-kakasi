"""Independent Python transcription of engine/src/tokenizer.cc pre_split().

Purpose: the C++ and Python versions must agree from separate derivations, and a failing
engine case can be pinned down to either the splitter or the byte literals in main.php.
"""
import re, sys

CT_SPACE, CT_NL, CT_DIGIT, CT_LETTER, CT_OTHER = range(5)


def ctype(b):
    if b in (10, 13):
        return CT_NL
    if b in (32, 9, 11, 12):
        return CT_SPACE
    if 48 <= b <= 57:
        return CT_DIGIT
    if (65 <= b <= 90) or (97 <= b <= 122) or b >= 0x80:
        return CT_LETTER
    return CT_OTHER


def is_ws(b):
    return ctype(b) in (CT_SPACE, CT_NL)


def not_ld_nl(b):
    t = ctype(b)
    return t not in (CT_LETTER, CT_DIGIT, CT_NL)


SUFS = [b"ll", b"re", b"ve", b"s", b"t", b"m", b"d"]


def contraction_len(t, i, n):
    if i >= n or t[i] != 0x27:
        return 0
    for s in SUFS:
        if i + 1 + len(s) > n:
            continue
        if t[i + 1:i + 1 + len(s)].lower() == s:
            return 1 + len(s)
    return 0


def pre_split(text):
    out = []
    n = len(text)
    i = 0
    while i < n:
        L = contraction_len(text, i, n)
        if L:
            out.append(text[i:i + L]); i += L; continue
        j = i
        if not_ld_nl(text[i]):
            j = i + 1
        k = j
        while k < n and ctype(text[k]) == CT_LETTER:
            k += 1
        if k > j:
            out.append(text[i:k]); i = k; continue
        if ctype(text[i]) == CT_DIGIT:
            jj = i
            while jj < n and jj - i < 3 and ctype(text[jj]) == CT_DIGIT:
                jj += 1
            out.append(text[i:jj]); i = jj; continue
        jj = i + 1 if text[i] == 0x20 else i
        k = jj
        while k < n and ctype(text[k]) not in (CT_SPACE, CT_LETTER, CT_DIGIT):
            k += 1
        if k > jj:
            while k < n and ctype(text[k]) == CT_NL:
                k += 1
            out.append(text[i:k]); i = k; continue
        run = i
        while run < n and is_ws(text[run]):
            run += 1
        if run > i:
            tail = run
            while tail > i and text[tail - 1] in (10, 13):
                tail -= 1
            if tail < run:
                out.append(text[i:run]); i = run; continue
            drop = 1 if run < n else 0
            take = run - i - drop
            if take >= 1:
                out.append(text[i:i + take]); i += take; continue
            out.append(text[i:run]); i = run; continue
        out.append(text[i:i + 1]); i += 1
    return out


if __name__ == "__main__":
    cases = [b"a ", b"a  ", b"a   ", b"   ", b"a  b", b"a,  b", b"a\nb", b"a\tb",
             b"a.\nb", b"a\n\nb", b"a \n", b"hello  \nworld", b"100", b"12345",
             b"don't", b"The capital of France is", b"The capital of France is Paris."]
    for c in cases:
        print(repr(c), "->", [p for p in pre_split(c)])
