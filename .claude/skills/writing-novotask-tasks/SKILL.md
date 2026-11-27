---
name: writing-novotask-tasks
description: Redacta tareas para NovoTask (título, prioridad, fecha límite, responsables y una descripción con Objetivo, Contexto y Criterios de aceptación) en español sencillo para jefes y clientes, con hechos concretos y sin jerga técnica ni rasgos de texto generado. Usar cuando se pidan tareas, pendientes, seguimiento o el resumen de un cambio, un error, un pase o una investigación para cargar en NovoTask.
---

# Redactar tareas para NovoTask

La tarea la leen jefes y clientes que no ven el código. Debe decir qué
se logra, qué pasó y cómo se comprueba, en el idioma del negocio y en
frases que se entienden a la primera lectura.

## 1. Reunir los hechos

Antes de escribir se tienen a mano estos datos. El que falte se pregunta;
no se inventa ni se rellena.

- [ ] Qué resultado espera el negocio.
- [ ] Qué pasó o qué se pidió, con un caso real (número de documento,
      cliente, pantalla).
- [ ] Cuántos casos afecta y desde cuándo.
- [ ] Estado hoy, con fecha (corregido, en pruebas, en producción).
- [ ] Qué falta y quién lo hace.
- [ ] Responsables, prioridad y fecha límite.

## 2. Decidir cuántas tareas

- Una tarea por resultado comprobable. Si dos partes tienen responsables
  o fechas distintas, son dos tareas.
- El trabajo técnico interno (revisión de código, ramas, despliegues
  intermedios) no lleva tarea propia salvo que alguien deba aprobarlo.

## 3. Llenar los campos

| Campo | Regla |
| --- | --- |
| Título | verbo en infinitivo y el resultado; hasta 70 caracteres |
| Prioridad | según la tabla de prioridades |
| Fecha límite | día en que debe quedar hecha, en formato dd/mm/aaaa |
| Responsables | nombre y apellido; varios si comparten la tarea |
| Descripción | la plantilla del paso 4 |

| Prioridad | Cuándo |
| --- | --- |
| Urgente | detiene la operación o afecta dinero o datos hoy |
| Alta | afecta a clientes o a la contabilidad esta semana |
| Media | mejora necesaria sin impacto inmediato |
| Baja | conveniente, puede esperar |

## 4. Escribir la descripción

```text
Objetivo
Una oración con el resultado para el negocio.

Contexto
Dos a cuatro oraciones cortas. Qué pasó, un caso real, cuántos casos
afecta y cómo está hoy, con fecha.

Criterios de aceptación
- Un comportamiento que se comprueba en una pantalla, un reporte o un dato.
- Otro comportamiento, uno por línea.
- Pendiente: la acción concreta que falta y quién la hace.
```

## 5. Estilo

1. Frases cortas, una idea por frase, unidas con punto o con «y».
2. Dos puntos solo tras «Pendiente». Nunca dentro de una frase.
3. Sin guiones largos, punto y coma, paréntesis largos, negritas,
   emojis ni encabezados con «#».
4. Sin nombres de clases, tablas, comandos, ramas, PR, archivos ni
   herramientas internas. Se nombra la pantalla, el documento o la
   persona.
5. Cifras, montos y fechas exactos. Nada de «varios», «algunos» o
   «recientemente».
6. Solo hechos. Sin frases de proceso ni de relleno; lo que falta va en
   «Pendiente».
7. Sin muletillas de texto generado («cabe destacar», «es importante»,
   «de manera eficiente», «robusto», enumeraciones de tres adjetivos).

| Así no | Así sí |
| --- | --- |
| «La factura quedó duplicada: una entró por la importación…» | «La factura quedó duplicada. Una entró por la importación…» |
| «Se refactorizó el servicio y se agregó una validación» | «Ya no se puede registrar dos veces el mismo documento» |
| «Funciona correctamente» | «Al repetir el documento, el sistema avisa y no guarda» |
| «Se hace después de la aprobación de QA» | «Pendiente: aprobación de QA en el ambiente de pruebas» |
| «Varios clientes tuvieron problemas recientemente» | «14 clientes tuvieron el error entre el 3/11 y el 10/11» |

## 6. Revisar antes de entregar

- [ ] El título empieza con verbo y lo entiende alguien ajeno al sistema.
- [ ] El objetivo cabe en una oración.
- [ ] El contexto tiene un caso real, una cifra y una fecha.
- [ ] Cada criterio se puede comprobar con sí o no.
- [ ] No hay términos técnicos ni dos puntos fuera de «Pendiente».
- [ ] La descripción no pasa de unas 150 palabras.

## 7. Entregar

Cada campo en su propia línea, listo para copiar al formulario, y la
descripción dentro de un bloque de texto. Si son varias tareas, se
numeran en el orden en que se ejecutan.

## Ejemplos

Corrección de un error.

```text
Objetivo
Que cada pedido se facture una sola vez.

Contexto
El pedido 8452 se facturó dos veces el 12/11 porque el botón de
facturar respondía lento y la cajera lo presionó de nuevo. Hubo 9 casos
en noviembre. Está corregido en producción desde el 18/11 y las 9
facturas repetidas ya se anularon.

Criterios de aceptación
- Un pedido facturado ya no muestra el botón de facturar.
- Si se presiona dos veces seguidas, se emite una sola factura.
- Pendiente: que contabilidad confirme las 9 notas de crédito.
```

Funcionalidad nueva.

```text
Objetivo
Que el cliente reciba por correo su estado de cuenta cada mes.

Contexto
Hoy el estado de cuenta se envía a mano y en octubre tomó dos días
para 320 clientes. El envío automático está listo en el ambiente de
pruebas desde el 20/11.

Criterios de aceptación
- El primer día hábil de cada mes cada cliente con saldo recibe su
  estado de cuenta en PDF.
- El cliente sin correo aparece en un reporte para enviarlo a mano.
- Pendiente: que gerencia apruebe el texto del correo.
```
