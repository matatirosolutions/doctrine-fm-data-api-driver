# Upgrading from 0.x to 1.0 #

1.0 targets Doctrine DBAL 4 and ORM 3, which is what Symfony 8 requires. The driver itself was largely rewritten (the 0.x
code lives on the `0.x` branch), but the mapping conventions and the helper classes' public API are unchanged.

## Requirements ##

* PHP 8.2+
* `doctrine/dbal` 4, `doctrine/orm` 3 (and `doctrine/doctrine-bundle` 3 if you use Symfony 8)
* `symfony/http-client` replaces `guzzlehttp/guzzle`; Guzzle is no longer required by the driver.

## What you need to change ##

* **Annotations to attributes.** ORM 3 only reads attribute (or XML) mapping. See the README for the conventions in attribute form.
* **Keys your application assigns** (a UUID set in the constructor, say) need no change.
* **`#[ORM\GeneratedValue]` keys which FileMaker assigns** (serial numbers, auto-enter Get(UUID)) need the new `GeneratedKeyListener`
  registered for the `loadClassMetadata` event; doctrine-filemaker-driver-bundle 1.11 does it for you. 0.x found the key column by
  inspecting the call stack for the entity manager; 1.0 doesn't, so without the listener Doctrine uses FileMaker's record id as the
  key. Mappings don't change. See the README.
* **If you map `FMIdentityGenerator` yourself** (`strategy: 'CUSTOM'`), add `insertable: false` to the key's `#[ORM\Column]`, so that
  Doctrine leaves it out of the INSERT; 0.x left it out if it was empty. If you forget, `FMIdentityGenerator` throws an exception
  explaining what to change. (The record created will have had its key blanked.)
* **Register the custom types you use** in the Doctrine configuration (`fmdatetime`, `fmdate`, `fmtime`, `fmint`, `fmfloat`, `fmhtml`, `fmarray`). Types no
  longer report their own name, so the names are whatever you register them as. DBAL 4 no longer has an `array` type, so register
  `FMArrayType` under a name of your own.
* **`FMTimeType`** now lives in `MSDev\DoctrineFMDataAPIDriver\Types` like the other types (it was declared in the namespace of the
  bundle, so it couldn't be autoloaded from this package).
* **Hosts which include a scheme** (`http://...`) are now honoured. Previously `https://` was always prepended.
* **Moved classes.** The code now lives in `src/`, and the Doctrine-facing classes are grouped like a regular DBAL driver. Update
  your configuration and imports (the helper classes in `Utility`, the `Types` and the `Exception`s keep their namespaces):

  | 0.x | 1.0 |
  | --- | --- |
  | `MSDev\DoctrineFMDataAPIDriver\FMDriver` | `MSDev\DoctrineFMDataAPIDriver\Driver\FMDriver` (your `driver_class`) |
  | `...\FMConnection`, `FMStatement`, `FMResult`, `ExceptionConverter` | `...\Driver\` |
  | `...\FMPlatform`, `FMKeywords` | `...\Platform\` |
  | `...\FMIdentityGenerator` | `...\Id\FMIdentityGenerator` |

* **TLS handshake failures** (`tlsv1 alert decode error`) against servers which can't handle recent OpenSSL: set the new
  `max_tls_version: '1.2'` driver option. See the README.

## Behaviour changes ##

* `COUNT` queries work, and filter on the server. Previously they ignored their criteria and then failed.
* UPDATE and DELETE requests look up the FileMaker record id once, not twice.
* Writes held back during a transaction are now discarded on rollback, and nested transactions (which DBAL 4 does with savepoints)
  are supported. Reading inside a transaction now returns results rather than an empty set.
* A mapped field which isn't present on the layout hydrates as `null`, rather than raising a PHP warning.
* An expired token is refreshed once per request rather than once for the lifetime of the connection, and a retried script request
  still returns the script result.
* **Logging in is lazy.** The driver no longer logs in when the connection is created, but on the first request, so a bad
  username, password or host surfaces as an `AuthenticationException` (or `FMException`) then, not when you connect.
* **`rec_meta` has a new shape**, `{"found": n, "fetch": n, "total": n}`, rather than the raw `dataInfo` from the Data API.
* **The date, time and timestamp types return `null`** for an empty or unparseable value instead of throwing. The new `FMIntType`
  and `FMFloatType` do the same for numbers, which FileMaker returns as `''` when empty.
* **A record found by a single value is remembered** (up to 500 records per connection), so updating or deleting it afterwards
  doesn't need another `_find` to get its FileMaker record id.
* A transport failure, or a request URL the HTTP client rejects, is reported as an `FMException` (or `AuthenticationException` when
  logging in) rather than escaping as a Symfony exception. A `101 Record is missing` response to a GET is an empty result.
* `FMException` and the other driver exceptions are still thrown as they are. They deliberately aren't wrapped in a DBAL `DriverException`.

## Helper classes ##

`ScriptAccess`, `SetGlobals` and `ContainerAccess` keep their constructors and method signatures. `ContainerAccess` accepts an optional
third `HttpClientInterface` argument. `SetGlobals` and `ContainerAccess` previously called `getWrappedConnection()`, which no longer exists
in DBAL 4 (and returned an object without the methods they called); they now use `getNativeConnection()`, as `ScriptAccess` always has.

## Removed ##

* `Utility\MetaData` and `QueryBuilder::getIdColumn()`, which found the entity manager by inspecting the call stack.
* `FMConnection::lastInsertId()` now returns FileMaker's record id (what the DBAL contract means by the last insert id). `FMIdentityGenerator` reads
  the mapped identifier field from that record, and `GeneratedKeyListener` applies it to `GeneratedValue` keys.
* Everything which existed only to satisfy DBAL 2/3's PDO-style statement API (`fetch()`, `fetchAll()`, `setFetchMode()` ...).
* The schema generation methods of `FMPlatform`: they could never be used, since FileMaker's schema isn't managed from code.
