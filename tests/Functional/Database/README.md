# Test database

`DoctrineFMTest.fmp12` is the database the functional tests (`tests/Functional`) run against. It is built from
`build.ndjson` with the `filemaker` CLI, so it can be rebuilt (`./build.sh`) or reviewed as text.

## Running the functional tests

1. Host `DoctrineFMTest.fmp12` on a FileMaker Server you can reach (or FileMaker Cloud) with the Data API enabled.
   The built-in `fmrest` extended privilege is already granted to the file's `API Test` privilege set.
2. Copy `../.env.dist` to `../.env.local` and set `FM_HOST` (the other values match the test database). Variables exported in your
   shell take precedence. Set `FM_VERIFY_TLS=0` for a local server with a self-signed certificate.
3. `vendor/bin/phpunit --testsuite functional`

Without `FM_HOST` and `FM_PASSWORD` every functional test is skipped, so the default `phpunit` run is unaffected.
Each test marks the records it creates and deletes them afterwards, so the file can stay hosted between runs.

## What is in it

| Element | Notes |
| --- | --- |
| Tables `Keyword`, `Note` | `ID` is the primary key (`Get ( UUID )`, prohibit modification, indexed); `Note::ID_Keyword` is the indexed foreign key |
| Housekeeping fields | `recCreateAccountName`, `recCreateTimestamp`, `recModifyAccountName`, `recModifyTimestamp` on both tables |
| `Keyword` data fields | `Name`, `Category`, `Weight` (number), `DateValue`, `TimeValue`, `Stamp`, `Body`, `Photo` (container), `Name_calc` (calculation) |
| Relationship | `Keyword::ID = Note::ID_Keyword` |
| Layouts `API_Keyword`, `API_Note` | Hidden, carry every field the tests use: these are what the entities map to |
| Layouts `Blank_Keyword`, `Blank_Note` | Hidden, no objects: script context only |
| Script `Echo Parameter ( json )` | Returns `{errorCode, message, data}` with the script parameter as `data` |
| Privilege set `API Test`, account `api_test` | Records and layouts full access, scripts execute only, no value lists |

Naming and structure follow the universal FileMaker baseline (the build used no standards pack).

## Things to know

- The `Admin` (full access) account has a random password written to `.admin-password` (git-ignored) by `build.sh`.
  Change it, and `api_test`'s password, before hosting this anywhere that is not a private test server.
- Rebuilding needs FileMaker Pro installed (the CLI drives the real engine) and refuses to overwrite an existing file.

## Troubleshooting

- **`TLS connect error ... tlsv1 alert decode error`, on every request.** The server can't parse the ClientHello of very recent
  OpenSSL builds (4.x, as in current Homebrew PHP): it chokes on their default signature algorithm list. Older clients are
  fine, and it isn't a credentials problem. Set `FM_MAX_TLS_VERSION=1.2` in `.env.local`, which makes the driver cap the TLS
  version (see the README, `max_tls_version`). The same switch works in your application's configuration.
