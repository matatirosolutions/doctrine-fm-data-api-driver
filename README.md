# Doctrine FileMaker Data API driver #

A Doctrine DBAL driver to interact with FileMaker using the v17+ Data API v1.

| Version | PHP    | Doctrine            | Symfony      |
|---------|--------|---------------------|--------------|
| 1.x     | >= 8.2 | DBAL 4.x, ORM 3.x   | 7.4, 8.x     |
| 0.x     | >= 8.1 | DBAL 2/3, ORM 2.x   | up to 7.x    |

Upgrading from 0.x? See [UPGRADE.md](UPGRADE.md).

## Installation ##

    composer require matatirosoln/doctrine-fm-data-api-driver

## Configuration ##

In your Doctrine configuration replace `driver:` with

```yaml
doctrine:
    dbal:
        driver_class: MSDev\DoctrineFMDataAPIDriver\Driver\FMDriver
        host: fm.example.com
        port: 443
        dbname: MyDatabase      # the FileMaker file name, without the extension
        user: '%env(FM_USER)%'
        password: '%env(FM_PASSWORD)%'
        # server_version: FMCloud   # if you're hosted on FileMaker Cloud, which also needs matatirosoln/fm-cloud-authentication
```

### HTTP client options ###

These go in the connection's `driverOptions` (`options:` in DoctrineBundle's configuration):

```yaml
doctrine:
    dbal:
        options:
            max_tls_version: '1.2'          # optional, see below
            http_client_options:            # any Symfony HttpClient option, e.g. for a self-signed certificate
                verify_peer: false
                verify_host: false
```

* `http_client` - your own `Symfony\Contracts\HttpClient\HttpClientInterface`, if you need full control (a PHP object, so only
  possible when you build the connection in code). Otherwise the driver creates a client with `HttpClient::create()`.
* `http_client_options` - default request options for the client the driver creates.
* `max_tls_version` - never negotiate a TLS version above this one (`1.2`, say). Off by default, so connections use TLS 1.3
  where the server offers it.

**When to use `max_tls_version`.** Some servers can't cope with the TLS handshake of recent OpenSSL builds (OpenSSL 4, which
current PHP builds can link against) and drop the connection with `tlsv1 alert decode error`, on every request, while older clients
work. Symfony's HTTP clients can only set a *minimum* TLS version, so with this option the driver makes its requests through its own
small curl-based client (`MaxTlsHttpClient`, needs `ext-curl`) which can cap it. The helper classes use the same client.

### Types ###

The driver ships with types for FileMaker's date, time and timestamp formats, for numbers, and for HTML encoded text and PHP
arrays. Register those you need under whatever names you like:

```yaml
doctrine:
    dbal:
        types:
            fmdatetime: MSDev\DoctrineFMDataAPIDriver\Types\FMDateTimeType   # m/d/Y H:i:s
            fmdate: MSDev\DoctrineFMDataAPIDriver\Types\FMDateType           # m/d/Y
            fmtime: MSDev\DoctrineFMDataAPIDriver\Types\FMTimeType           # H:i
            fmint: MSDev\DoctrineFMDataAPIDriver\Types\FMIntType           # '' or non-numeric becomes null
            fmfloat: MSDev\DoctrineFMDataAPIDriver\Types\FMFloatType       # '' or non-numeric becomes null
            fmhtml: MSDev\DoctrineFMDataAPIDriver\Types\FMHTMLType
            fmarray: MSDev\DoctrineFMDataAPIDriver\Types\FMArrayType
```

FileMaker returns an empty string for an empty number, date or time field. The date, time, int and float types turn that (and any
value they can't parse) into `null` rather than throwing, so map those properties as nullable. Zero is a value, not an empty field.

## Conventions ##

1. Create a model to represent a FileMaker **layout**. Set the name of the layout as the model table.

   ```php
   #[ORM\Entity(repositoryClass: KeywordRepository::class)]
   #[ORM\Table(name: 'Keyword')]
   class Keyword
   ```

2. In your model create an id field which is mapped to the special rec_id pseudo field

   ```php
   #[ORM\Column(name: 'rec_id', type: 'integer')]
   private ?int $id = null;
   ```

3. Create your 'actual' ID field, as used for relationships, as a separate property of your model. Set its GeneratedValue
   strategy to be `CUSTOM` which will mean that Doctrine will wait for FM to assign that value - the assumption being that this
   is an auto-enter calc field (probably Get(UUID)). You then need to specify the CustomIdGenerator and set this to
   `MSDev\DoctrineFMDataAPIDriver\Id\FMIdentityGenerator` so that the value is returned as a string.

   Map the column with `insertable: false`. That stops Doctrine sending an empty value with the INSERT, which would replace the one
   FileMaker generates. After the INSERT the generator reads the value back from the new record (if you forget, it tells you).

   ```php
   use MSDev\DoctrineFMDataAPIDriver\Id\FMIdentityGenerator;

   #[ORM\Id]
   #[ORM\Column(name: '__pk_ClientID', type: 'string', length: 255, insertable: false)]
   #[ORM\GeneratedValue(strategy: 'CUSTOM')]
   #[ORM\CustomIdGenerator(class: FMIdentityGenerator::class)]
   private ?string $uuid = null;
   ```

   If you'd rather use FileMaker's own record id as the identifier, map `rec_id` as the id with `#[ORM\GeneratedValue(strategy: 'IDENTITY')]`
   and no custom generator.

   Alternatively you could generate the UUIDs in your model constructor (using for example [ramsey/uuid](https://github.com/ramsey/uuid)).
   In this case you'd end up with something like

   ```php
   #[ORM\Id]
   #[ORM\Column(name: '__pk_CanvasID', type: 'string', length: 255)]
   private string $uuid;

   public function __construct()
   {
       $this->uuid = Uuid::uuid4()->toString();
   }
   ```

4. Add other properties as required. To access related fields on your layout enclose the field name in single quotes in the column mapping.

   ```php
   #[ORM\Column(name: "'absCon::email'", type: 'string', length: 255)]
   private ?string $contactEmail = null;
   ```

5. If you need access to the record modification ID you can add the special mod_id pseudo property

   ```php
   #[ORM\Column(name: 'mod_id', type: 'integer')]
   private ?int $modId = null;
   ```

6. If you need to know how many records matched the query which loaded a record, map the special rec_meta pseudo property.
   It holds JSON of the form `{"found": 12, "fetch": 5, "total": 340}`: the number of records the query matched, the number
   returned (limited by the query's limit), and the total number of records in the table.

   ```php
   #[ORM\Column(name: 'rec_meta', type: 'string', nullable: true)]
   private ?string $meta = null;
   ```

## Considerations ##

1. Because of the way in which more 'conventional' databases handle relationships, there is no concept of a portal. To access related data
   create a corresponding model for that table (layout) and create standard Doctrine relationships (OneToOne, OneToMany, ManyToOne etc).
2. If your model contains calculation fields you will run into issues when trying to create a new record, since Doctrine will try and set
   those fields to null. One 'solution' to this is to create a 'stub' of your model which contains only the fields which are necessary to
   create a new record and to instantiate that for record creation. If you head down this route you'll likely want to create an interface
   which both your stub and your real entity implement so that you can typehint appropriately.
3. If you wish to perform an IN query, then it needs to be the last `andWhere` set in the query builder so that the previous conditions
   will be applied to all of the query objects which the Data API requires to simulate an IN query.
4. `COUNT` queries are supported (including `$repository->count()`), and are answered from the found count the Data API reports. Other
   aggregate functions, `GROUP BY` and joins aren't.
5. FileMaker has no transactions. To approximate them while a transaction is open (which includes the duration of every `flush()`),
   `UPDATE` and `DELETE` requests are held back until the outermost transaction commits, and discarded if it rolls back. Nested
   transactions (`wrapInTransaction()`, savepoints) only discard their own writes. `INSERT`s are always sent immediately, because the
   record's key is needed straight away. A commit which fails part way through leaves the earlier writes in place.

## Helper classes ##

These are available whatever your entities look like, and all take the Doctrine `Connection`.

```php
$scripts = new ScriptAccess($connection);
$scripts->executeScript('Layout', 'Script name', 'parameter');                       // ['error' => ..., 'result' => ...]
$scripts->performScript('Layout', $recordId, 'Script name', 'parameter', true);

(new SetGlobals($connection))->setGlobals(['Table::gField' => 'value']);

$containers = new ContainerAccess($connection, $scripts);
$containers->performContainerInsert('Layout', $recordId, 'Field', '/path/to/file');
$containers->getStreamedContainerContent($url);       // a container URL as returned in a Data API response
$containers->getExternalContainerContent($path);      // externally stored (e.g. Streaming_SSL) content
```

The [Doctrine FileMaker bundle](https://github.com/matatirosolutions/doctrine-filemaker-driver-bundle "Doctrine FileMaker bundle")
creates services for these, so they can be autowired.

## Testing ##

The test suite runs the real Doctrine ORM on top of the driver with the Data API replaced by a mock, so it needs no FileMaker server.

    composer install
    vendor/bin/phpunit

The `unit` and `integration` suites never leave the process. The `functional` suite talks to a real FileMaker Server
and is skipped unless it is configured: host `tests/Functional/Database/DoctrineFMTest.fmp12`, copy
`tests/Functional/.env.dist` to `tests/Functional/.env.local` and set `FM_HOST`, then run

    vendor/bin/phpunit --testsuite functional

See `tests/Functional/Database/README.md` for what the test database contains and how it is rebuilt.
