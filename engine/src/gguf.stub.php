<?php
// GGUF reader ABI, implemented in src/gguf.cc.

function gguf_load(string $path): bool {}

function gguf_error(): string {}

function gguf_validate(): string {}

function gguf_accounting_ok(): bool {}

function gguf_tensor_count(): int {}

function gguf_tensor_name(int $index): string {}

function gguf_tensor_dtype(int $index): int {}

function gguf_tensor_dtype_name(int $index): string {}

function gguf_tensor_ne(int $index, int $dim): int {}

function gguf_tensor_bytes(int $index): int {}

function gguf_tensor_offset(int $index): int {}

function gguf_meta_str(string $key): string {}

function gguf_token_str(int $id): string {}

function gguf_token_decode(int $id): string {}
// Token ids whose decoded UTF-8 text uses only bytes from the charset (CSV).
function gguf_charset_ids(string $charset): string {}

function gguf_meta_num(string $key): float {}

function gguf_data_start(): int {}

function gguf_avail(): int {}
