# Informe de cambio — Paginación del Feed

## 1. Objetivo

Limitar la respuesta del feed a **20 samples por página** (configurable) y entregar metadatos de paginación.

## 2. Cambios realizados en Jophiel

* **Controlador `app/controller/FeedController.php`**
  * Soporte a los parámetros de consulta `page` y `per_page` (`per_page` por defecto = 20, máximo = 100).
  * Reemplazo de `limit(200)`/`pluck()` por `paginate()` de Eloquent (`LengthAwarePaginator`).
  * Se mantiene *fallback* para usuarios sin recomendaciones: se usan los últimos `SampleVector`.
  * La respuesta ahora incluye:
    * `sample_ids` — array con los IDs de la página solicitada.
    * `pagination` — objeto con `current_page`, `per_page`, `total`, `last_page`, `next_page_url`, `prev_page_url`.
* No se modificaron rutas ni modelos: el endpoint sigue siendo `GET /v1/feed/{user_id}`.

## 3. Formato de respuesta (ejemplo)

```json
{
  "user_id": 123,
  "generated_at": "2024-06-30T12:34:56Z",
  "sample_ids": [87, 45, 32, …],
  "pagination": {
    "current_page": 1,
    "per_page": 20,
    "total": 78,
    "last_page": 4,
    "next_page_url": "/v1/feed/123?page=2&per_page=20",
    "prev_page_url": null
  }
}
```

## 4. Impacto en Sword

1. **Primera página**: llamada actual sin parámetros sigue funcionando.
2. **Paginación**: añadir `?page=N` (1-based) y opcionalmente `&per_page=K` (≤ 100).
3. Los campos `pagination.*` sustituyen a los anteriores (`first_page_url`, `last_page_url`, etc.).
4. `sample_ids` conserva el nombre, por lo que la lógica de presentación apenas necesita leer `pagination`.

## 5. Pruebas realizadas

| Caso | Resultado |
|------|-----------|
| Usuario sin feed personalizado | Devuelve últimos samples + paginación correcta |
| Usuario con 78 recomendaciones (`per_page` 20) | 4 páginas, enlaces `next/prev` correctos |
| Valores límite (`per_page` 5 / 150) | Se aplican 5 y 100 respectivamente |
| Parámetros omitidos | Configuración por defecto (20 elementos, página 1) |

## 6. Pasos siguientes

* Ajustar en Sword la lectura de `pagination` y construcción de enlaces «Siguiente / Anterior».
* Si se requiere compatibilidad con la estructura antigua, ignorar `pagination` y usar `sample_ids` como hasta ahora.

---

Con este ajuste, el backend controla la paginación y evita enviar colecciones grandes, reduciendo consumo de ancho de banda y memoria en los clientes. 