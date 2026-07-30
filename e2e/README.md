# E2E suite

Browser tests for the whole product with the LLM faked out: the
`fake` provider (Modules/Llm/Drivers/FakeDriver) generates instantly
with no network, so every resource and feature is exercised before an
API key exists.

The suite runs against its own SQLite database (`database/e2e.sqlite`),
never your dev database. Global setup migrates it fresh, runs the seed
master, then `demo:seed --fake-llm`.

    cd e2e
    npm install
    npx playwright install chromium   # once; CI does this itself
    npm test

If a Chromium already exists on the machine, point at it instead of
downloading: `CHROMIUM_PATH=/path/to/chromium npm test`.
