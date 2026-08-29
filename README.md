# rag-chatbot-laravel

RAG Chatbot de portafolio: responde preguntas sobre PDFs subidos por el usuario
citando la fuente. Backend Laravel (API REST + Queue), Postgres+pgvector como
vector DB, Anthropic Claude para generación y Voyage AI para embeddings.
Frontend React (todavía no arrancado).

Las decisiones técnicas de cada parte del stack están en
[`docs/decisiones-tecnicas.md`](docs/decisiones-tecnicas.md).

## Setup local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
```

## Seguridad

- **Autenticación**: Sanctum con doble token — access token (ability `access`,
  20 min) + refresh token (ability `refresh`, 7 días). Todas las rutas de
  negocio requieren `auth:sanctum` + `abilities:access`, así un refresh token
  filtrado no sirve para llamar a la API real.
- **Rate limiting**: limiters nombrados `auth`, `chat` y `upload` definidos en
  `AppServiceProvider`. `chat` y `upload` limitan por usuario autenticado, no
  por IP, para frenar abuso de la cuota de Anthropic/Voyage.
- **API keys de LLM**: `ANTHROPIC_API_KEY` y `VOYAGE_API_KEY` viven únicamente
  en el `.env` del backend. Ningún endpoint de la API las devuelve, loguea ni
  las expone de ninguna forma, y el build de React nunca las lee (no llevan
  prefijo `VITE_`). Todas las llamadas a Anthropic/Voyage las hace el backend;
  el frontend solo habla con la API de Laravel.
- **Uploads** (a implementarse junto con el módulo de documentos): solo PDF,
  tamaño máximo validado, archivo guardado en disco privado (nunca en
  `public`).

### Limitación conocida: refresh token rotation

El endpoint `/api/auth/refresh` revoca el par actual y emite uno nuevo de forma
atómica (`TokenPairService::rotate` corre dentro de una transacción). Lo que
**todavía no** maneja es la concurrencia: dos requests simultáneos con el mismo
refresh token pueden emitir dos pares nuevos (race condition), y un refresh
token robado puede usarse mientras siga vigente.

La solución completa es *refresh token rotation con detección de reuso* (OWASP):
marcar el refresh como usado bajo lock y, si llega un reuso, revocar toda la
familia de tokens del usuario por sospecha de robo. Es una feature en sí misma
y queda pendiente como ticket propio de *hardening de auth*, fuera del alcance
de la seguridad base.
