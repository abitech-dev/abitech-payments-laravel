# Plantillas por tipo de documento

## Guía práctica

```markdown
# Desplegar en UAT

Para quien publica una versión en UAT. Deja el entorno con la rama
`staging` en marcha y comprobado.

## Requisitos

- Acceso al servidor y a la rama `staging`.

## Pasos

1. Pasar las puertas de calidad en local.
   Resultado: todas en verde.
2. ...

## Comprobar

- `GET /up` responde 200.

## Problemas frecuentes

| Síntoma | Causa | Solución |
| --- | --- | --- |
```

## Referencia

```markdown
# Variables de entorno

Valores que lee la aplicación y su efecto.

| Variable | Obligatoria | Valor por defecto | Efecto |
| --- | --- | --- | --- |
```

## Explicación

```markdown
# Ciclo del acta

Por qué el acta pasa por revisión con el cliente antes de firmarse.

## Contexto

## Cómo funciona

(diagrama Mermaid si aclara)

## Consecuencias
```

## Tutorial

```markdown
# Primer servicio de principio a fin

Recorrido guiado para quien empieza: programar, llenar el acta y
aprobar el informe con las cuentas de ejemplo.

## Antes de empezar

## 1. ...

## Qué sigue
```

## ADR (`docs/decisiones/NNNN-titulo.md`)

```markdown
# 0001. Título de la decisión

- Estado: aceptada
- Fecha: 2026-10-12

## Contexto

## Decisión

## Consecuencias
```

## CHANGELOG.md

```markdown
# Changelog

Formato: Keep a Changelog 1.1; versiones: SemVer 2.0.

## [Sin publicar]

### Añadido
### Cambiado
### Corregido
### Eliminado
### Seguridad
```
