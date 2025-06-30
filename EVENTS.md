# Contrato de Integración de Eventos: Sword & Jophiel

Este documento define la especificación de los eventos que el CMS `sword` debe emitir y que el sistema `Jophiel` consumirá para mantener actualizados los modelos de recomendación.

## I. Infraestructura (RabbitMQ)

La comunicación se realizará a través de un bus de mensajes RabbitMQ.

  - **Exchange:**

      - **Nombre:** `sword_events`
      - **Tipo:** `topic`
          - *Justificación: El uso de un topic exchange nos da la máxima flexibilidad para que en el futuro, otros servicios puedan suscribirse a subconjuntos de eventos sin impactar a Jophiel (ej. un servicio de analíticas podría suscribirse a `user.*`).*

  - **Cola para Jophiel:**

      - **Nombre:** `jophiel_consumer_queue`
      - **Binding Key (Routing Key):** Jophiel se suscribirá a dos tipos de eventos principales:
          - `*.interaction`: Para todas las interacciones de los usuarios.
          - `sample.lifecycle.*`: Para la creación o eliminación de samples.

## II. Formato General del Mensaje

Todos los eventos deben seguir una estructura JSON estándar para consistencia y facilidad de procesamiento.

```json
{
  "event_name": "dominio.contexto.accion",
  "event_id": "uuid-aqui",
  "event_timestamp": "YYYY-MM-DDTHH:MM:SSZ",
  "payload": {
    // ... datos específicos del evento
  }
}
```

  - `event_name`: Identificador único del tipo de evento.
  - `event_id`: Un UUID v4 para garantizar la idempotencia y evitar el doble procesamiento.
  - `event_timestamp`: Fecha y hora en formato ISO 8601 UTC en que ocurrió el evento.
  - `payload`: Un objeto JSON con la información relevante al evento.

## III. Catálogo de Eventos a Emitir por Sword

### A. Interacciones de Usuario (Routing Key: `user.interaction`)

Estos eventos alimentan tanto la **Reacción Inmediata** como el **Proceso Batch**.

**1. `user.interaction.like`** (Alta Prioridad)

  - **Disparador:** Un usuario da 'like' a un sample.
  - **Payload:**
    ```json
    {
      "user_id": 123,
      "sample_id": 456
    }
    ```

**2. `user.interaction.follow`** (Alta Prioridad)

  - **Disparador:** Un usuario sigue a otro (un creador).
  - **Payload:**
    ```json
    {
      "user_id": 123,
      "followed_user_id": 789
    }
    ```

**3. Otros eventos de alta prioridad (a implementar):**

  - `user.interaction.share`
  - `user.interaction.comment`
  - `user.interaction.add_to_board`

**4. Interacciones para el Proceso Batch (Menor Prioridad):**

  - `user.interaction.play`
  - `user.interaction.skip`
  - `user.interaction.dislike`
  - **Payload (todos igual):**
    ```json
    {
      "user_id": 123,
      "sample_id": 456
    }
    ```

### B. Ciclo de Vida del Contenido (Routing Key: `sample.lifecycle.*`)

Estos eventos aseguran que Jophiel tenga los mismos samples que `sword`.

**1. `sample.lifecycle.created`**

  - **Disparador:** Un nuevo sample ha sido analizado por `casiel` y su metadata está lista.
  - **Payload:**
    ```json
    {
      "sample_id": 789,
      "creator_id": 101,
      "metadata": {
          "bpm": 120,
          "genero": ["techno", "house"],
          "emocion_es": ["enérgico", "oscuro"],
          "instrumentos": ["synth", "drums"],
          "tipo": ["loop"],
          // ... resto de la metadata de casiel
      }
    }
    ```

**2. `sample.lifecycle.deleted`**

  - **Disparador:** Un sample ha sido eliminado de `sword`.
  - **Payload:**
    ```json
    {
      "sample_id": 789
    }
    ```

## IV. ¿Cómo consume Sword las recomendaciones?

Jophiel no "empuja" el feed a `sword`. La arquitectura es de tipo **pull**.

1.  Cuando un usuario inicie sesión o pida su feed en `sword`, el backend de `sword` debe consultar a Jophiel.
2.  Esta consulta se puede hacer de dos maneras (a definir la preferida):
      - **A) Acceso Directo a la BD (Fase 1):** `sword` realiza una consulta SQL a la tabla `user_feed_recommendations`.
        ```sql
        SELECT sample_id
        FROM user_feed_recommendations
        WHERE user_id = :current_user_id
        ORDER BY score DESC
        LIMIT 200;
        ```
      - **B) Endpoint API en Jophiel (Recomendado para desacoplar):** Jophiel expone un endpoint HTTP interno.
          - **Endpoint:** `GET /v1/feed/{user_id}`
          - **Respuesta:**
            ```json
            {
              "user_id": 123,
              "sample_ids": [456, 789, 101, 202, ...],
              "generated_at": "YYYY-MM-DDTHH:MM:SSZ"
            }
            ```

La opción **B** es preferible para mantener los servicios desacoplados y facilitar el mantenimiento a futuro.