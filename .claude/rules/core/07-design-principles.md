# 07. Principios de diseño (simple, sin duplicar, solo lo pedido)

DIRECTIVA: la solución más simple que cumple el requisito vigente, con
cada responsabilidad y cada regla de negocio en un solo sitio.

## Estándares de referencia

- Separation of Concerns · Single Responsibility (SOLID)
- DRY (Hunt y Thomas, *The Pragmatic Programmer*)
- KISS · YAGNI (Beck, *Extreme Programming Explained*)
- TDD (Beck, *Test-Driven Development by Example*)

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| Una unidad que valida, decide y persiste | una unidad por responsabilidad |
| La misma regla de negocio en dos sitios | un punto único; el otro lo llama |
| Abstracción con un solo consumidor | código directo hasta el segundo |
| Parámetro, bandera o capa «por si acaso» | lo que pide el requisito de hoy |
| Código propio para lo que trae el framework | la pieza del framework |
| Arreglo sin prueba que falle antes | prueba en rojo, luego el arreglo |

## Reglas

1. **Separación.** Una unidad, una razón para cambiar. Cómo se reparte
   en cada tecnología lo dice su regla `tech/`.
2. **DRY es conocimiento, no texto.** Una regla de negocio, un cálculo o
   un catálogo vive en un solo sitio. Dos bloques parecidos que cambian
   por motivos distintos no se unen.
   - Un espejo entre cliente y servidor se admite si el inventario del
     proyecto lo declara y una prueba ata los dos lados.
3. **KISS.** Primero lo que trae el lenguaje o el framework; después el
   código directo; lo configurable, solo con un segundo caso real.
4. **YAGNI.** Se construye lo que pide el requisito vigente. Sin
   parámetros, banderas, capas ni interfaces sin consumidor.
   - Quedan fuera los controles de `core/05` y lo que el proyecto marca
     como «(decisión del cliente)» o «(decisión de producto)».
5. **Prueba primero.**
   - Un error se corrige con la prueba que lo reproduce, vista en rojo
     antes del arreglo.
   - Una regla de negocio, un permiso o una transición de estado llevan
     su prueba antes que el código.
   - Una pantalla lleva su prueba en el mismo cambio.
   - El procedimiento está en la skill `testing-changes`.
6. **Documentar.** Nombres según `core/01`, comentarios según `core/02` y
   documentación en el mismo cambio que el código (`tech/documentation`).
