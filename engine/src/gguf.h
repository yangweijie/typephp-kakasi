// Shared between the cl-compiled loader and the icx-compiled SYCL ops.
// Only plain C++ types cross this boundary (proven ABI-compatible: cl 14.44 and
// clang-cl-based icx both use the same MSVC STL and mangling).
#pragma once

#include <cstdint>
#include <vector>

namespace gguf {

bool loaded();
int tensor_count();
int tensor_dtype(int index);
std::string tensor_name(int index);
int64_t tensor_ne(int index, int dim);
int64_t tensor_bytes(int index);
int64_t tensor_offset(int index);
int64_t data_start();

double meta_num(const std::string& key);
std::string meta_str(const std::string& key);

// String arrays (tokenizer.ggml.tokens / .merges), fully retained at load.
int arr_size(const std::string& key);
std::string arr_at(const std::string& key, int i);
// Direct access for callers that scan a whole array; arr_at copies and re-looks-up per call.
const std::vector<std::string>* arr_ptr(const std::string& key);

// Index of a tensor by exact name, or -1.
int find_tensor(const std::string& name);

// Reads the tensor's raw quantized bytes from disk (data_start + offset).
bool raw_bytes(int index, std::vector<uint8_t>& out);

// Reads a byte subrange of a tensor's data (used for row streaming of big tensors).
bool raw_bytes_range(int index, uint64_t byte_off, uint64_t len, std::vector<uint8_t>& out);

}  // namespace gguf
