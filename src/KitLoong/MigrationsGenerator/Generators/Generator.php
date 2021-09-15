<?php

namespace KitLoong\MigrationsGenerator\Generators;

use Doctrine\DBAL\Schema\Table;
use Illuminate\Support\Str;
use KitLoong\MigrationsGenerator\Generators\Blueprint\SchemaBlueprint;
use KitLoong\MigrationsGenerator\Generators\Blueprint\TableBlueprint;
use KitLoong\MigrationsGenerator\Generators\Methods\SchemaBuilder;
use KitLoong\MigrationsGenerator\Generators\Writer\MigrationWriter;
use KitLoong\MigrationsGenerator\MigrationMethod\TableProperty;
use KitLoong\MigrationsGenerator\MigrationsGeneratorSetting;

class Generator
{
    private $migrationWriter;
    private $columnGenerator;
    private $filenameGenerator;
    private $foreignKeyGenerator;
    private $indexGenerator;
    private $setting;

    public function __construct(
        MigrationWriter $migrationWriter,
        ColumnGenerator $columnGenerator,
        FilenameGenerator $filenameGenerator,
        ForeignKeyGenerator $foreignKeyGenerator,
        IndexGenerator $indexGenerator,
        MigrationsGeneratorSetting $setting
    ) {
        $this->migrationWriter     = $migrationWriter;
        $this->columnGenerator     = $columnGenerator;
        $this->filenameGenerator   = $filenameGenerator;
        $this->foreignKeyGenerator = $foreignKeyGenerator;
        $this->indexGenerator      = $indexGenerator;
        $this->setting             = $setting;
    }

    /**
     * @param  \Doctrine\DBAL\Schema\Table  $table
     * @param  \Doctrine\DBAL\Schema\Column[]  $columns
     * @param  \Doctrine\DBAL\Schema\Index[]  $indexes
     * @return string file path
     */
    public function generateTable(Table $table, array $columns, array $indexes): string
    {
        $up = $this->getSchemaBlueprint($table, SchemaBuilder::CREATE);

        $blueprint = new TableBlueprint();

        if ($this->shouldSetCharset()) {
            $blueprint = $this->setTableCharset($blueprint, $table);
            $blueprint->setLineBreak();
        }

        // Example
        // $table->foreign('user_id')->references(['id'])->on('users_mysql57');
        // $table->foreign(['user_id', 'sub_id'])->references(['id', 'sub_id'])->on('users_mysql57');
        // $table->getIndexes() will return extra "IDX_*" index for column user_id
        // Use $indexes instead.
        $this->indexGenerator->setSpatialFlag($indexes, $table->getName());
        $singleColumnIndexes = $this->indexGenerator->getSingleColumnIndexes($indexes);
        $multiColumnsIndexes = $this->indexGenerator->getMultiColumnsIndexes($indexes);

        foreach ($columns as $column) {
            $columnMethod = $this->columnGenerator->generate($table, $column, $singleColumnIndexes);
            $blueprint->setColumnMethod($columnMethod);
        }

        $blueprint->mergeTimestamps();

        if ($multiColumnsIndexes->isNotEmpty()) {
            $blueprint->setLineBreak();
            foreach ($multiColumnsIndexes as $index) {
                $columnMethod = $this->indexGenerator->generate($table, $index);
                $blueprint->setColumnMethod($columnMethod);
            }
        }

        $up->setBlueprint($blueprint);

        $down      = $this->getSchemaBlueprint($table, SchemaBuilder::DROP_IF_EXISTS);
        $path      = $this->filenameGenerator->generateCreatePath($table->getName());
        $className = $this->filenameGenerator->generateCreateClassName($table->getName());

        $this->migrationWriter->writeTo(
            $path,
            $this->setting->getStubPath(),
            $className,
            $up,
            $down
        );

        return $path;
    }

    /**
     * @param  \Doctrine\DBAL\Schema\Table  $table
     * @param  \Doctrine\DBAL\Schema\ForeignKeyConstraint[]  $foreignKeys
     * @return string file path
     */
    public function generateForeignKeys(Table $table, array $foreignKeys): string
    {
        $up          = $this->getSchemaBlueprint($table, SchemaBuilder::TABLE);
        $upBlueprint = new TableBlueprint();
        foreach ($foreignKeys as $foreignKey) {
            $columnMethod = $this->foreignKeyGenerator->generate($table, $foreignKey);
            $upBlueprint->setColumnMethod($columnMethod);
        }
        $up->setBlueprint($upBlueprint);

        $down          = $this->getSchemaBlueprint($table, SchemaBuilder::TABLE);
        $downBlueprint = new TableBlueprint();
        foreach ($foreignKeys as $foreignKey) {
            $columnMethod = $this->foreignKeyGenerator->generateDrop($foreignKey);
            $downBlueprint->setColumnMethod($columnMethod);
        }
        $down->setBlueprint($downBlueprint);

        $path      = $this->filenameGenerator->generateForeignKeyPath($table->getName());
        $className = $this->filenameGenerator->generateForeignKeyClassName($table->getName());

        $this->migrationWriter->writeTo(
            $path,
            $this->setting->getStubPath(),
            $className,
            $up,
            $down
        );

        return $path;
    }

    private function shouldSetCharset(): bool
    {
        if ($this->setting->getPlatform() !== Platform::MYSQL) {
            return false;
        }

        return $this->setting->isUseDBCollation();
    }

    private function setTableCharset(TableBlueprint $blueprint, Table $table): TableBlueprint
    {
        $blueprint->setProperty(
            TableProperty::COLLATION,
            $collation = $table->getOptions()['collation']
        );

        $charset = Str::before($collation, '_');
        $blueprint->setProperty(TableProperty::CHARSET, $charset);

        return $blueprint;
    }

    /**
     * @param  \Doctrine\DBAL\Schema\Table  $table
     * @param  string  $schemaBuilder
     * @return \KitLoong\MigrationsGenerator\Generators\Blueprint\SchemaBlueprint
     */
    private function getSchemaBlueprint(Table $table, string $schemaBuilder): SchemaBlueprint
    {
        return new SchemaBlueprint(
            $this->setting->getConnection()->getName(),
            $table->getName(),
            $schemaBuilder
        );
    }
}