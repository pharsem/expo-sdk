# Contributing

Thank you for your help.

## Set up the project

```bash
composer install
git config core.hooksPath .githooks
```

The second command turns on the commit message hook. Run it one time for each clone.

## Before you open a pull request

Run both checks. They must pass.

```bash
composer test      # PHPUnit
composer analyse   # PHPStan level 8
```

## Commit messages

The project uses [Conventional Commits](https://www.conventionalcommits.org/).
The hook rejects a message in another format.

```
type(scope): summary

An optional body. Leave one empty line before it.
```

| Type | Use it for | Version bump |
| --- | --- | --- |
| `feat` | A new feature of the public API. | Minor |
| `fix` | A bug fix. | Patch |
| `perf` | A change that makes the code faster. | Patch |
| `docs` | The README, the docblocks, or another document. | None |
| `test` | A test only. | None |
| `refactor` | A change that keeps the behavior. | None |
| `style` | Whitespace or formatting. | None |
| `build` | composer.json or the package files. | None |
| `ci` | A workflow or a hook. | None |
| `chore` | Anything else. | None |
| `revert` | A revert of an earlier commit. | Depends |

Mark a breaking change with an exclamation mark after the type. Add a
`BREAKING CHANGE:` line to the body, and say what the user must change.

```
feat(message)!: rename the image field to richContent

BREAKING CHANGE: Call richContent() in place of image().
```

Keep the first line under 72 characters. Write it in the imperative.

## Versions

The project uses [semantic versioning](https://semver.org/). The git tag holds the
version. composer.json holds no `version` field, because Packagist reads the tag.

The public API is the contract:

- Every `public` class, method, constant, and property in `src/`.
- The JSON that `PushMessage` builds for the Expo API.
- The name and the parent of every exception.

These parts are not the contract. They can change in a patch release:

- Anything `private`.
- The text of an exception message.
- The `tests/` and the `examples/` directories.

| Change | Bump |
| --- | --- |
| A new method, or a new optional argument at the end. | Minor |
| A new field of the Expo API. | Minor |
| A bug fix that keeps the signature. | Patch |
| A removed method, or a renamed argument. | Major |
| A raised PHP version. | Major |
| A new required argument. | Major |

## Cut a release

1. Set `Expo::VERSION` in `src/Expo.php` to the new version.
2. Move the unreleased section of CHANGELOG.md under the new version and the date.
3. Commit both with `chore(release): prepare 1.2.0`.
4. Tag the commit with `git tag v1.2.0` and push it with `git push origin v1.2.0`.

The release workflow then checks the tag against `Expo::VERSION`, runs the tests,
and creates the GitHub Release. Packagist reads the new tag through the webhook.

## Report a problem

Open an issue with the PHP version, the SDK version, and the payload that failed.
Remove the access token and the device tokens first.
