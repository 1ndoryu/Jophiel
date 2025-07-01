# Contrato de Integración de Eventos: Sword & Jophiel
**Versión: 1.0**

Este documento es la única fuente de verdad y define la especificación técnica de los eventos que el CMS `sword` debe emitir para que el sistema de recomendación `Jophiel` pueda operar correctamente. El cumplimiento de este contrato es crucial para garantizar la integridad y el rendimiento del ecosistema.

## I. Infraestructura de Comunicación (RabbitMQ)

La comunicación entre servicios se realizará a través de un bus de mensajes RabbitMQ para garantizar el desacoplamiento y la escalabilidad.

-   **Exchange:**
    -   **Nombre:** `sword_events`
    -   **Tipo:** `topic`
        -   **Justificación:** El uso de un *topic exchange* proporciona la máxima flexibilidad. Permite que Jophiel (y futuros servicios) se suscriban a patrones de eventos específicos (ej. `user.interaction.*`) sin necesidad de modificar el emisor (`sword`).

-   **Cola para Jophiel:**
    -   **Nombre:** `jophiel_consumer_queue`
    -   **Binding Keys (Routing Keys):** Jophiel se suscribirá a los siguientes patrones para recibir todos los eventos que le conciernen:
        -   `user.interaction.*`
        -   `sample.lifecycle.*`

## II. Formato General del Mensaje

Todos los eventos deben adherirse estrictamente a la siguiente estructura JSON para asegurar la consistencia y facilitar el procesamiento automatizado.

```json
{
  "event_name": "dominio.contexto.accion",
  "event_id": "uuid-v4-generado-aqui",
  "event_timestamp": "YYYY-MM-DDTHH:MM:SSZ",
  "source": "sword.v2",
  "payload": {
    // ... datos específicos del evento
  }
}
```

  - `event_name`: (String) Identificador único y estandarizado del tipo de evento.
  - `event_id`: (String) Un UUID v4 para garantizar la idempotencia y prevenir el doble procesamiento accidental.
  - `event_timestamp`: (String) La fecha y hora en formato ISO 8601 UTC en la que ocurrió el evento en `sword`.
  - `source`: (String) Identificador del servicio que origina el evento (ej. "sword.v2").
  - `payload`: (Object) Un objeto JSON que contiene toda la información relevante y necesaria para que Jophiel procese el evento.

## III. Nomenclatura de Eventos

Para mantener la claridad, todos los `event_name` seguirán la convención `dominio.contexto.accion`.

  - **Dominio:** La entidad principal sobre la que actúa el evento. Para Jophiel, los dominios de interés son `user` y `sample`.
  - **Contexto:** El sub-dominio o la naturaleza del evento. Los más comunes serán `interaction` y `lifecycle`.
  - **Acción:** La acción específica que se realizó (`like`, `created`, `updated`, etc.).

## IV. Catálogo de Eventos a Emitir por Sword

A continuación se detalla cada evento que `sword` debe emitir.

### A. Interacciones de Usuario (Routing Key: `user.interaction.*`)

Estos eventos informan sobre las acciones que los usuarios realizan sobre los samples. Son la base para el entrenamiento de los modelos de gusto.

#### Eventos de Alta Prioridad (Para Reacción Inmediata)

Estos eventos disparan una actualización casi instantánea del feed del usuario afectado.

**1. `user.interaction.like`**

  - **Disparador:** Un usuario da 'like' a un sample.
  - **Payload:**
    ```json
    {
      "user_id": 123,
      "sample_id": 456
    }
    ```

**2. `user.interaction.follow`**

  - **Disparador:** Un usuario (`user_id`) sigue a un creador (`followed_user_id`).
  - **Payload:**
    ```json
    {
      "user_id": 123,
      "followed_user_id": 789
    }
    ```

**3. `user.interaction.add_to_board`**

  - **Disparador:** Un usuario añade un sample a uno de sus tableros (colecciones).
  - **Payload:**
    ```json
    {
      "user_id": 123,
      "sample_id": 456,
      "board_id": 101
    }
    ```

**(Opcional, a futuro) Otros eventos de alta prioridad:**

  - `user.interaction.share`
  - `user.interaction.comment`

-----

#### Eventos de Baja Prioridad (Para Proceso Batch)

Estos eventos se registran y son procesados periódicamente por el sistema principal de Jophiel.

**1. `user.interaction.play`, `user.interaction.skip`, `user.interaction.dislike`**

  - **Payload (para todos):**
    ```json
    {
      "user_id": 123,
      "sample_id": 456
    }
    ```

**2. `user.interaction.unlike`**

  - **Disparador:** Un usuario deshace un 'like' previo.
  - **Payload:**
    ```json
    {
      "user_id": 123,
      "sample_id": 456
    }
    ```

**3. `user.interaction.unfollow`**

  - **Disparador:** Un usuario deja de seguir a un creador.
  - **Payload:**
    ```json
    {
      "user_id": 123,
      "unfollowed_user_id": 789
    }
    ```

-----

### B. Ciclo de Vida del Contenido (Routing Key: `sample.lifecycle.*`)

Estos eventos son cruciales para mantener la base de datos de `sample_vectors` de Jophiel sincronizada con la base de datos de contenidos de `sword`.

**1. `sample.lifecycle.created`**

  - **Disparador:** Un nuevo sample ha sido subido a `sword` **y** su metadata ha sido completamente analizada por el servicio `casiel`. Este evento es la señal para que Jophiel ingiera y vectorize el nuevo sample.
  - **Payload:** El payload **debe** contener el `sample_id`, el `creator_id` y el objeto `metadata` completo generado por `casiel`.
    ```json
    {
      "sample_id": 789,
      "creator_id": 101,
      "metadata": {
        "bpm": 108,
        "tags": ["guitar", "melancholy"],
        "tipo": "loop",
        "escala": "minor",
        "genero": ["ambient", "lofi"],
        "emocion_es": ["triste", "melancólico", "reflexivo"],
        "instrumentos": ["guitar"],
        // ... resto completo del JSON de casiel
      }
    }
    ```

**2. `sample.lifecycle.updated`**

  - **Disparador:** Se actualiza la metadata de un sample existente en `sword` (ej. se corrigen los géneros, se cambia el BPM). Jophiel necesita este evento para recalcular el vector del sample.
  - **Payload:** Similar a `created`, debe contener el `sample_id` y la **metadata completa actualizada**.
    ```json
    {
      "sample_id": 789,
      "creator_id": 101,
      "metadata": {
          // ... objeto completo con la nueva metadata
      }
    }
    ```

**3. `sample.lifecycle.deleted`**

  - **Disparador:** Un sample ha sido eliminado permanentemente de `sword`. Jophiel lo eliminará de su sistema, incluyendo los feeds de los usuarios.
  - **Payload:**
    ```json
    {
      "sample_id": 789
    }
    ```

## V. Flujo de Consumo de Recomendaciones

Jophiel no "empuja" activamente las recomendaciones a `sword`. La arquitectura es de tipo **pull**, lo que significa que `sword` es responsable de solicitar el feed de un usuario cuando lo necesita.

Cuando un usuario solicita su feed principal en la aplicación cliente, el backend de `sword` debe obtener la lista ordenada de `sample_id`.

**Método Recomendado: Endpoint API en Jophiel**

Para mantener un desacoplamiento máximo entre los servicios, Jophiel expondrá un endpoint HTTP interno y seguro para este propósito.

  - **Endpoint:** `GET /v1/feed/{user_id}`
  - **Autenticación:** (A definir) Acceso por IP interna o token de servicio-a-servicio.
  - **Respuesta Exitosa (200 OK):**
    ```json
    {
      "user_id": 123,
      "generated_at": "2025-06-30T22:00:00Z",
      "sample_ids": [456, 789, 101, 202, 303, ...]
    }
    ```
  - **Acción de Sword:** Al recibir esta lista de IDs, `sword` debe realizar una única consulta a su propia base de datos (`contents`) para obtener los detalles completos de esos samples, preservando el orden recibido, y enviarlos al cliente final.

**Método Alternativo (Fase 1): Acceso Directo a la BD**

Como solución temporal, `sword` podría consultar directamente la tabla `user_feed_recommendations` de Jophiel. **Esta opción no es recomendada a largo plazo** ya que acopla fuertemente las bases de datos de ambos servicios.

```sql
SELECT sample_id FROM user_feed_recommendations
WHERE user_id = :current_user_id
ORDER BY score DESC
LIMIT 200;
```
