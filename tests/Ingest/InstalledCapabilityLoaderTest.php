<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Ingest;

use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Ingest\InstalledCapabilityLoader;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use PHPUnit\Framework\TestCase;

/**
 * El cruce de los dos grafos: lo que una DISTRIBUCIÓN provee entra al mismo grafo que los plugins.
 *
 * Hasta hoy `capabilityProvisions` llegaba en `[]` desde todos los llamadores, así que el grafo sólo
 * veía plugins. Una app podía depender de un paquete y el resolver no tenía cómo saber si estaba
 * puesto — y la tabla capacidad→paquete, que existe para recomendar de dónde sacarlo, no podía
 * dispararse nunca.
 */
final class InstalledCapabilityLoaderTest extends TestCase
{
    private string $vendor;

    protected function setUp(): void
    {
        $this->vendor = sys_get_temp_dir() . '/milpa-vendor-' . bin2hex(random_bytes(4));
        mkdir($this->vendor . '/composer', 0o775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->vendor . '/composer/installed.json');
        @rmdir($this->vendor . '/composer');
        @rmdir($this->vendor);
    }

    /** @param list<array<string, mixed>> $paquetes */
    private function escribir(array $paquetes, bool $envuelto = true): void
    {
        file_put_contents(
            $this->vendor . '/composer/installed.json',
            json_encode($envuelto ? ['packages' => $paquetes] : $paquetes, JSON_THROW_ON_ERROR),
        );
    }

    /** Lo que un paquete declara de sí mismo llega al grafo con su contrato y su procedencia. */
    public function testWhatAPackageDeclaresBecomesAProvision(): void
    {
        $this->escribir([[
            'name' => 'milpa/tool-runtime',
            'extra' => ['milpa' => ['capability' => [
                'id' => 'tool-runtime',
                'provides' => ['tool.registry'],
                'contracts' => ['tool.registry' => 'Milpa\\ToolRuntime\\ToolRegistry'],
            ]]],
        ]]);

        $p = InstalledCapabilityLoader::fromVendor($this->vendor);

        self::assertCount(1, $p);
        self::assertSame('tool.registry', $p[0]->id);
        self::assertSame('Milpa\\ToolRuntime\\ToolRegistry', $p[0]->interface);
        self::assertSame('milpa/tool-runtime', $p[0]->service, 'la procedencia es el paquete');
        self::assertNull($p[0]->contractVersion, 'nadie declaró una, y eso se dice');
    }

    /**
     * UN ID SIN CONTRATO DECLARADO SE OMITE, no se le fabrica uno.
     *
     * El nombre del paquete no es una interfaz. Un grafo que sabe menos es honesto; uno que afirma un
     * contrato que nadie escribió es el certificado sustituto de siempre.
     */
    public function testAnIdWithoutADeclaredContractIsSkippedAndNotInvented(): void
    {
        $this->escribir([[
            'name' => 'milpa/mcp-server',
            'extra' => ['milpa' => ['capability' => [
                'id' => 'mcp',
                'provides' => ['surface.mcp'],
            ]]],
        ]]);

        self::assertSame([], InstalledCapabilityLoader::fromVendor($this->vendor));
    }

    /** Sin `installed.json` devuelve vacío: «no lo pude saber» no se disfraza de «no hay nada». */
    public function testWithoutTheRecordItReturnsEmptyInsteadOfGuessing(): void
    {
        self::assertSame([], InstalledCapabilityLoader::fromVendor($this->vendor));
    }

    /** El formato viejo de Composer también se lee: un lock más viejo que la herramienta es lo normal. */
    public function testTheBareArrayFormIsReadToo(): void
    {
        $this->escribir([[
            'name' => 'milpa/ai-gateway',
            'extra' => ['milpa' => ['capability' => [
                'id' => 'ai-gateway',
                'provides' => ['agent.model'],
                'contracts' => ['agent.model' => 'Milpa\\AiGateway\\LlmService'],
            ]]],
        ]], envuelto: false);

        self::assertCount(1, InstalledCapabilityLoader::fromVendor($this->vendor));
    }

    /**
     * EL CIRCUITO COMPLETO, en sus dos caras — que es lo que este cruce existe para permitir.
     *
     * Con el paquete instalado el grafo CIERRA; sin él, la capacidad falta y el reporte trae la
     * recomendación concreta de dónde sacarla. Antes de esto la segunda cara existía en una tabla y
     * la primera era imposible: sin provisiones de distribución, requerir una capacidad de paquete
     * rompía el arranque aunque el paquete estuviera puesto.
     */
    public function testTheGraphClosesWhenInstalledAndRecommendsWhenNot(): void
    {
        $perfil = new HostProfile('app', '1.0', requiredCapabilities: ['tool.registry']);

        $this->escribir([[
            'name' => 'milpa/tool-runtime',
            'extra' => ['milpa' => ['capability' => [
                'id' => 'tool-runtime',
                'provides' => ['tool.registry'],
                'contracts' => ['tool.registry' => 'Milpa\\ToolRuntime\\ToolRegistry'],
            ]]],
        ]]);

        $conElPaquete = (new GraphResolver())->resolve(new ResolutionInput(
            hostProfile: $perfil,
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: InstalledCapabilityLoader::fromVendor($this->vendor),
            capabilityRequirements: [],
        ));

        self::assertSame([], $conElPaquete->errors, 'con el paquete puesto, el grafo cierra');

        $sinElPaquete = (new GraphResolver())->resolve(new ResolutionInput(
            hostProfile: $perfil,
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        ));

        self::assertNotSame([], $sinElPaquete->errors, 'sin el paquete, la capacidad falta');

        $acciones = [];
        foreach ($sinElPaquete->errors as $error) {
            foreach ($error->toArray()['recommendedActions'] ?? [] as $accion) {
                $acciones[] = $accion;
            }
        }

        self::assertContains(
            ['type' => 'install-package', 'package' => 'milpa/tool-runtime'],
            $acciones,
            'y el reporte dice de dónde sacarlo — la recomendación que hasta hoy no podía dispararse',
        );
    }
}
