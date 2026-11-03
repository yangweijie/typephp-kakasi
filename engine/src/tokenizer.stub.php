<?php
// Byte-level BPE ABI, implemented in src/tokenizer.cc.

function tok_last_error(): string {}

// Returns the encoded ids as a comma-separated string; empty string on failure.
function tok_encode(string $text): string {}

// Encode one chat turn (role/content -> ids with the model's ChatML markers).
function tok_chat(string $role, string $content): string {}

// 1 when this model's chat template uses the ChatML dialect tok_chat renders.
function tok_chat_template_matches(): int {}
function tok_chat_open(string $role): string {}

// Tool turns from the model's own template: assistant <tool_call> and user <tool_response>.
function tok_tool_call(string $name, string $args_json): string {}
function tok_tool_result(string $content): string {}
// Parallel calls: both blobs "@@TOOLCALL@@"-separated, same order.
function tok_tool_calls(string $names, string $args_json): string {}
