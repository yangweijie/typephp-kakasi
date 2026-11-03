import subprocess

M = "C:/Users/ASUS/.lmstudio/models/arzaan789/qwen2.5-0.5b-uncensored/qwen2.5_0.5b_uncensored.Q4_K_M.gguf"
BIN = "C:/llama.cpp-master/build/ReleaseCPU/bin/llama-tokenize.exe"

IM_START = "<|" + "im_start" + "|>"
IM_END = "<|" + "im_end" + "|>"

cases = {
    "c_start": "user" + "\n" + IM_START,
    "c_end": "content" + IM_END + "\n",
    "c_chat": IM_START + "user" + "\n" + "Hi" + IM_END + "\n",
    "c_plain": "user\nHi\n",
}

for name, text in cases.items():
    path = "tq_%s.txt" % name
    with open(path, "w", newline="") as f:
        f.write(text)
    out = subprocess.run([BIN, "-m", M, "-f", path, "--ids", "--log-disable"],
                         capture_output=True, text=True, stdin=subprocess.DEVNULL)
    print("%-9s in=%-28r -> %s" % (name, text, out.stdout.strip().replace("\r", "")))
