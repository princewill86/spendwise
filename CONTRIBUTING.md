# Contributing to Spendwise

Fork the repository, clone your fork, and follow the local development instructions in the [README](README.md) to install dependencies, set up PostgreSQL and run migrations.

Use a focused branch such as `fix/transfer-validation` or `feature/budget-periods`. Use a short imperative subject in each commit, such as `Validate transfer destination currency`.

Before opening a pull request, run the checks in the README. Explain the user-facing change, describe how you tested it, and include screenshots if you changed the interface. Use synthetic data in screenshots and test fixtures. Add regression coverage to protect changes to financial calculations, access controls, and authentication. Keep database migrations reversible and avoid including credentials, personal data, or build output.

## Capturing README screenshots

Start a seeded local installation with synthetic data. Install the Playwright browser and run the capture command from the repository root.

```sh
npx playwright install chromium
npm run screenshots
```

The command captures the landing page, demo dashboard, and dark mobile homepage. It saves the PNG files under `docs/screenshots/` and embeds them in `README.md` using relative links that GitHub renders on the repository page.

The script can use the credentials created by the local preview setup. For another demo installation, set `SPENDWISE_BROWSER_URL`, `SPENDWISE_DEMO_EMAIL`, and `SPENDWISE_DEMO_PASSWORD` before running it. Review the files and include the three PNGs and the updated README in the same commit. The manual **README screenshots** GitHub Actions workflow also produces them as a downloadable artifact.
