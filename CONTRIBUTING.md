# Contribuir

Gracias por querer mejorar el paquete. Esta guía resume lo que revisa la
integración continua, para que tu PR pase a la primera.

## Preparar el entorno

```bash
git clone https://github.com/abitech-dev/abitech-payments-laravel.git
cd abitech-payments-laravel
composer install
composer test
```

Las pruebas usan Orchestra Testbench con sqlite en memoria. No necesitan
credenciales de ninguna pasarela.

## Antes de abrir el PR

- Cada cambio de comportamiento lleva su prueba en `tests/`.
- `composer lint` (PSR-12 con Pint) y `composer analyse` (PHPStan nivel 5)
  pasan sin errores. `vendor/bin/pint` corrige el formato.
- Los montos son cadenas decimales y se calculan con `bcmath`.
- Una pasarela o un driver nuevo entra con su referencia en `docs/`, su fila
  en «Drivers disponibles» del README y su variable en el bloque `.env`.
- Los ejemplos usan datos de prueba, nunca credenciales reales.

## Mensajes de commit

Se usan [Conventional Commits](https://www.conventionalcommits.org/es/v1.0.0/),
como `feat(stripe): reembolsar una parte del cobro` o
`fix(mercadopago): leer la firma sin el prefijo`.

Cada cambio que nota quien usa el paquete suma su línea en la sección
`[Sin publicar]` del `CHANGELOG.md`, en el mismo PR.

## Publicación

Las versiones siguen [SemVer](https://semver.org/lang/es/) sobre la API
pública, que son los contratos, los DTO, la configuración, los eventos y las
migraciones. Un `fix` sube el parche, un `feat` la menor y un cambio
incompatible la mayor.

1. Un PR cambia `## [Sin publicar]` por la versión con su fecha, por ejemplo
   `## [3.3.0] - 2026-12-01`, y deja arriba una sección `[Sin publicar]`
   vacía.
2. Al unirlo, la Action crea la etiqueta `v3.3.0` y el release de GitHub con
   esas notas.
3. Packagist publica la etiqueta sola.
