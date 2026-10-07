#!/bin/zsh
# Real-service check for OpenRouter: Ahena's provider (read-only harness) and Square Sync's adapter.
# Asks for the test key without echoing it; it's only in this process's environment and is unset at the end.
set -u
printf "OpenRouter TEST key (input hidden): "; read -s OPENROUTER_API_KEY; echo; export OPENROUTER_API_KEY
echo "== Ahena @ahena/provider-openrouter (read-only live harness)"
( cd /Users/hungcao/Development/Ahena-openrouter && AHENA_LIVE=openrouter OPENROUTER_MODEL=openai/gpt-4.1-mini pnpm --filter @ahena/provider-openrouter exec vitest run test/live.test.ts 2>&1 | grep -E "✓|✗|×|Drift state|Report:|Tests |FAIL" )
echo "== Square WooCommerce Sync OpenRouter adapter"
( cd "/Volumes/dev/projects/Cao-Tech WP Plug-In/square-woo-sync" && php tests/ai/live.php )
unset OPENROUTER_API_KEY
