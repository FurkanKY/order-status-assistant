# Same plugin and prompt, model switched from Claude Opus 5.5 to Claude Haiku 5.5

The only change is the model setting (`osa_settings.model`), passed with `--model claude-haiku-5-5`. Haiku 5.5 costs $0.10 / $0.50 per million input / output tokens against Opus 5.5's $4 / $20, and the plugin sends Haiku requests without the refusal fallback, which Haiku 5.5 doesn't support.
