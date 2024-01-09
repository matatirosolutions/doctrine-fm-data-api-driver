<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace MSDev\DoctrineFMDataAPIDriver;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use MSDev\DoctrineFMDataAPIDriver\ExceptionConverter;
use MSDev\DoctrineFMDataAPIDriver\Exception\MethodNotSupportedException;

/**
 * FileMaker PHP API Driver.
 *
 * @author Steve Winter <steve@msdev.co.uk>
 */
class FMDriver implements Driver
{
    /**
     * @throws Exception\AuthenticationException
     * @throws DBALException
     */
    public function connect(array $params, $username = null, $password = null, array $driverOptions = array()): FMConnection
    {
        return new FMConnection($params, $this);
    }

    public function getName(): string
    {
        return 'filemaker_dapi';
    }

    public function getDatabase(Connection $conn): string
    {
        $params = $conn->getParams();
        return $params['dbname'];
    }

    public function getDatabasePlatform(): FMPlatform
    {
        return new FMPlatform();
    }

    /**
     * @throws MethodNotSupportedException
     */
    public function getSchemaManager(Connection $conn, AbstractPlatform $platform): AbstractSchemaManager
    {
        throw new MethodNotSupportedException('code-based schema changes are not supported');
    }

    public function getExceptionConverter(): ExceptionConverterInterface
    {
        return new ExceptionConverter();
    }
}
