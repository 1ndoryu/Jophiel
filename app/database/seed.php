#!/usr/bin/env php
<?php
/**
 * Script para poblar la base de datos con datos de prueba para Jophiel.
 * Uso: php jophiel db:seed [--users=X --samples=Y]
 *
 * Este script ahora es una capa de compatibilidad. La lógica principal reside en
 * app\commands\SeedCommand.php y es invocada directamente por el CLI 'jophiel'.
 */

// El bootstrap se gestiona a través del comando 'jophiel'.
echo "Nota: Ejecutando seeder con valores por defecto. Para personalizar, use las opciones --users y --samples.\n";
(new app\commands\SeedCommand())->run([]); // Ejecuta el nuevo comando con un array de opciones vacío.