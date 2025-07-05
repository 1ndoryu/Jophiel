# API de Búsqueda Híbrida (`/v1/search`)

Este endpoint combina la relevancia textual (Full-Text Search de PostgreSQL) con la personalización algorítmica de Jophiel para devolver una lista ordenada de samples.

---

## 1. Endpoint

```
GET /v1/search
```

### Parámetros de consulta

| Parámetro  | Tipo      | Obligatorio | Descripción                                                           |
| ---------- | --------- | ----------- | --------------------------------------------------------------------- |
| `q`        | `string`  | Sí          | Término de búsqueda. Se normaliza con `plainto_tsquery`.              |
| `user_id`  | `integer` | Sí          | ID del usuario que realiza la búsqueda (se usa para personalización). |
| `page`     | `integer` | No          | Página a solicitar (empieza en **1**). _Default:_ `1`.                |
| `per_page` | `integer` | No          | Resultados por página (1-100). _Default:_ `20`.                       |

---

## 2. Respuesta

```json
{
    "user_id": 42,
    "generated_at": "2023-11-21T15:34:12Z",
    "sample_ids": [123, 456, 789],
    "pagination": {
        "current_page": 1,
        "per_page": 20,
        "total": 57,
        "last_page": 3,
        "next_page_url": null,
        "prev_page_url": null
    }
}
```

### Campos

-   **`sample_ids`** Array ordenado por puntuación híbrida (`SearchScoreFinal`).
-   **`pagination`** Objeto con datos estándar de paginación.

---

## 3. Ejemplo de consumo

```bash
curl -X GET "https://jophiel.local/v1/search?q=guitar+ambient&user_id=42&per_page=10&page=2"
```

Respuesta abreviada:

```json
{
  "user_id": 42,
  "generated_at": "2023-11-21T15:34:12Z",
  "sample_ids": [987, 654, 321],
  "pagination": { ... }
}
```

---

## 4. Detalles de cálculo de la puntuación

```
SearchScoreFinal = (text_rank * peso_texto) + (ScoreFinal * peso_personalizacion)
```

Los pesos viven en `config/search.php`:

```php
'score_weights' => [
    'text_relevance'  => 0.5,
    'personalization' => 0.5,
],
```

### text_rank

Valor de `ts_rank()` calculado por PostgreSQL sobre la columna `search_tsv`.

### ScoreFinal

Valor calculado por `ScoreCalculationService`, considerando similitud de vectores, seguimiento y novedad.

---

## 5. Errores comunes

| Código | Significado       | Causa típica                     |
| ------ | ----------------- | -------------------------------- |
| `400`  | Petición inválida | Falta `user_id` o es 0.          |
| `500`  | Error interno     | Excepción inesperada (ver logs). |

---

## 6. Versionado

Este endpoint forma parte de la versión **v1** de la API y seguirá el mismo ciclo de versionado semántico que el resto de Jophiel.
