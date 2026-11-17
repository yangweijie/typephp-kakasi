<?php
// Forward-pass ABI, implemented in src/model.cc -> src/model_sycl.cc.
// Tokens are passed as a comma-separated id string.

function model_last_error(): string {}

function model_residency_report(): string {}

function model_top_logit(): float {}

function model_second_logit(): float {}

function model_expect_logit(): float {}

function model_logsumexp(): float {}
function model_token_prob(int $id, float $temp): float {}
function model_rand01(): float {}
function model_sample_excluding(int $exclude, float $temp): int {}
function model_peek_argmax(): int {}

function model_expect_rank(): int {}

function model_trace_count(): int {}

function model_trace_at(int $i): float {}
function model_stage_count(): int {}
function model_stage_at(int $i): float {}
function model_plan(int $backend, int $ctx): string {}
function model_set_budget_gib(float $gib): int {}
// F648: 0 restores the shipped 0.55 share of device memory for resident weights.
function model_set_residency_frac(float $f): int {}
function model_residency_frac(): float {}
function model_residency_frac_intent(): float {}
function model_set_kv_f16(int $on): int {}
function model_kv_cache_gib(): float {}
function model_set_weight_prec(int $p): int {}
function model_weight_prec(): int {}
// F365/F366: 0 = score cache, 1 = three-pass (same arithmetic), 2 = split-KV (same sum,
// grouped differently).
function model_set_attn_mode(int $m): int {}
function model_attn_mode(): int {}
// F369: chunk count of the last attention pass (1 = not split).
function model_attn_split_p(): int {}
// F392: drafter lookup window in use (default 3, KAKASI_SPEC_WINDOW 1..6).
function model_spec_window(): int {}
function model_session_start(int $backend, string $tokens): int {}
function model_session_next(int $backend, float $temp, float $top_p): int {}
function model_session_len(): int {}
function model_session_next_allowed(int $backend, string $allowed): int {}
function model_eos_id(): int {}
// Sampling seed for the streaming session (session_start resets it to 12345).
function model_session_seed(int $seed): int {}
// Park/resume one of two conversation snapshots (slot 0 or 1).
function model_session_save(int $slot): int {}
function model_session_restore(int $slot): int {}
// F359 continuous batching: two conversations decode in ONE forward pass. batch_start allocates
// the two KV regions (cap rows each); batch_add prefills one conversation into a slot with its
// own temperature, nucleus and seed; batch_next advances every live conversation by one token in
// a single pass and returns the number of rows in that pass; batch_tokens returns the token each
// conversation emitted ("t0,t1", -1 when a conversation produced none).
function model_batch_start(int $backend, int $cap): int {}
// F373: `slots` KV regions (1..8) so one step can carry 3 or 4 conversations.
function model_batch_start_n(int $backend, int $cap, int $slots): int {}
function model_batch_add(int $backend, int $slot, string $ids, float $temp, float $top_p, int $seed): int {}
function model_batch_stop(int $slot): int {}
function model_batch_reset(): int {}
function model_batch_next(int $backend): int {}
function model_batch_tokens(): string {}
function model_batch_len(int $slot): int {}
function model_batch_end(): int {}
function model_spec_generate(int $backend, string $tokens, int $n_new, int $k): string {}
function model_spec_stats(): string {}
function model_spec_fix(int $on): int {}
function model_spec_freq(int $on): int {}
function model_spec_multiwin(int $on): int {}
function model_spec_selfseed(int $on): int {}
function model_spec_tree(int $backend, string $tokens, int $n_new, int $bins, int $depth): string {}
function model_spec_tree_sample(int $backend, string $tokens, int $n_new, int $bins, int $depth, float $temp, int $seed): string {}
function model_cache_store(int $backend, string $tokens, string $path): int {}
function model_cache_prefill(int $backend, string $tokens, string $path): int {}
function model_device_fingerprint(int $backend): string {}
function model_set_split(int $backend, int $layer): int {}
function model_split_state(): string {}
// "" = not a directory; "!N" = directory with N .gguf files (N != 1); else the .gguf path.
function model_dir_gguf(string $dir): string {}
// "name|bytes" lines for files in dir ending with ext.
function model_dir_files(string $dir, string $ext): string {}
// Bucket index for the prefix-cache file (same prompt -> same bucket).
function model_cache_key(string $ids, int $buckets): int {}

function model_prefill(int $backend, string $tokens, int $expect): int {}

function model_generate(int $backend, string $tokens, int $n_new): string {}
function model_generate_sampled(int $backend, string $tokens, int $n_new, float $temp, float $top_p, int $seed): string {}
function model_nucleus_size(): int {}
// F363: compares the sort-free sampler against the sorted walk on the model's real logits.
// Returns "err=none draws=N mism=N amb=N grid=N exact=N ids=N".
function model_sampler_check(int $backend, string $tokens, int $draws, int $seed): string {}

function model_gen_prefill_ms(): float {}

function model_gen_step_ms(): float {}
