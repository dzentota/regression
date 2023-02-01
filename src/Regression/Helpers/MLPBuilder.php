<?php
declare(strict_types=1);

namespace Regression\Helpers;

class MLPBuilder
{
    private string $version;
    private string $packageName;
    private array $manifest = [];

    private string $path;

    private array $manifestInstallDefs = [];

    private array $files = [];

    public function __construct(string $version, string $packageName)
    {
        $this->version = $version;
        $this->packageName = $packageName;
        $this->path = sys_get_temp_dir() . '/' . $packageName . '.zip';
    }

    public function addManifestInstallDef(string $from, string $to): self
    {
        $this->manifestInstallDefs[$from] = $to;

        return $this;
    }

    public function addFile(string $path, string $contents, string $copyTo = null): self
    {
        $this->files[$path] = $contents;

        return $this->addManifestInstallDef($path, $copyTo ?? $path);
    }

    public function withManifest(array $manifest): self
    {
        $this->manifest = $manifest;
        return $this;
    }

    public function updateManifest(string $key, $value): self
    {
        $this->manifest[$key] = $value;
        return $this;
    }


    public function build(): self
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }

        $manifestContent = $this->getManifestContent();
        $manifestInstallDefs = $this->getManifestInstallDefsContent();

        $manifestContents = <<<PHP
<?php
$manifestContent
$manifestInstallDefs
PHP;

        $archive = new \ZipArchive();
        $archive->open($this->path, \ZipArchive::CREATE);

        $archive->addFromString('manifest.php', $manifestContents);

        foreach ($this->files as $path => $contents) {
            $archive->addFromString($path, $contents);
        }

        $archive->close();

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    private function getManifestContent(): string
    {
        $manifest = array_merge($this->getDefaultManifest(), $this->manifest);
        return '$manifest = ' . var_export($manifest, true) . ';';
    }

    /**
     * @return string
     */
    private function getManifestInstallDefsContent(): string
    {
        $defs = [];

        foreach ($this->manifestInstallDefs as $from => $to) {
            $defs[] = ['from' => '<basepath>/' . $from, 'to' => ($from === $to)? 'custom/' . $to : $to];
        }

        $packageName = $this->packageName;
        $installDefs = [
            'id' => $packageName,
            'copy' => $defs
        ];
        return '$installdefs = ' . var_export($installDefs, true) . ';';
    }

    private function getDefaultManifest(): array
    {
        return [
            'built_in_version' => $this->version,
            'acceptable_sugar_versions' => ['*.*.*'],
            'acceptable_sugar_flavors' => ['ENT', 'ULT'],
            'readme' => '',
            'key' => '',
            'author' => '',
            'description' => '',
            'icon' => '',
            'is_uninstallable' => true,
            'name' => $this->packageName,
            'version' => 1674655842,
            'type' => 'module',
            'remove_tables' => 'prompt',
            'published_date' => (new \DateTime())->format('Y-m-d H:i:s')
        ];
    }
}