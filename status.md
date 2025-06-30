### **Resumen Ejecutivo del Proyecto: Jophiel**

**1. Visión del Proyecto**

Jophiel es un sistema de recomendación y personalización de contenido inteligente y altamente eficiente, diseñado para potenciar una plataforma de samples de audio. Su misión es conectar a los artistas con los sonidos que necesitan, presentando a cada usuario un feed de contenido único y curado algorítmicamente según sus gustos, interacciones y conexiones sociales.

**2. Arquitectura General**

Jophiel operará como un servicio independiente que se integra con el CMS `sword`. La arquitectura está diseñada para una máxima eficiencia y escalabilidad, basándose en un modelo híbrido de procesamiento:

* **Análisis Profundo (Proceso Batch):** Un motor principal que se ejecuta en segundo plano de forma periódica. Este proceso analiza todas las nuevas interacciones de los usuarios, actualiza sus perfiles de gusto (vectores) y pre-calcula las listas de recomendaciones. Es el cerebro pensante del sistema.
* **Reacción Inmediata (Event-Driven):** Un sistema ligero que se activa en tiempo real ante interacciones del usuario (como un `like`). No recalcula todo, sino que "inyecta" o reordena contenido relevante en el feed visible del usuario, proporcionando una sensación de respuesta instantánea.

**3. Flujo de Datos y Componentes Clave**

1.  **Ingestión y Vectorización:** El servicio `casiel` analiza cada `audio_sample` nuevo y genera una rica metadata en formato JSON. Jophiel consume esta metadata y la transforma en un vector numérico (`sample_vector`), que es el "ADN" del sample.
2.  **Registro de Interacciones:** Toda acción del usuario (like, dislike, play, skip, follow, etc.) se registra como un evento ponderado en una tabla `user_interactions`.
3.  **Actualización de Perfiles:** El Proceso Batch consume las interacciones para actualizar el `user_taste_profile` de cada usuario, que es un vector que refleja numéricamente sus gustos.
4.  **Cálculo de Recomendaciones:** El Proceso Batch compara los perfiles de usuario con los vectores de los samples para calcular un `ScoreFinal` para cada par usuario-sample.
5.  **Entrega Rápida:** Los resultados (el top N de recomendaciones para cada usuario) se almacenan en una tabla `user_feed_recommendations`. Cuando un usuario solicita su feed, la aplicación simplemente lee esta tabla pre-calculada, garantizando una respuesta casi instantánea.

**4. Modelo de Datos (Esquema `jophiel` en PostgreSQL)**

* `sample_vectors`: Almacena el vector numérico (ADN) de cada sample.
* `user_taste_profiles`: Almacena el vector de gustos de cada usuario.
* `user_interactions`: Registro de todas las interacciones de los usuarios, que actúa como cola de entrada para el Proceso Batch.
* `user_feed_recommendations`: Tabla final con los resultados pre-calculados (el feed de cada usuario).
* `recommendation_cache`: Tabla de caché para resultados de "samples similares" y "ideas para tableros", para evitar recálculos.

**5. Lógica de Puntuación (Fórmula del `ScoreFinal`)**

El score que determina el orden de las recomendaciones es una fórmula compuesta y ponderada:

`ScoreFinal = (FactorSimilitud * 1.0) + (FactorSeguimiento * 0.5) + (FactorNovedad * 0.2) + FactorPenalizacion`

* **FactorSimilitud:** Compatibilidad matemática (similitud del coseno) entre el vector del usuario y el del sample. Es la base de la personalización.
* **FactorSeguimiento:** Un bono si el sample es de un creador al que el usuario sigue.
* **FactorNovedad:** Un pequeño bono que decae con el tiempo para dar visibilidad al contenido nuevo.
* **FactorPenalizacion:** Una penalización drástica (-1000) para ocultar contenido con el que el usuario ya ha interactuado de forma "definitiva" (like, dislike, etc.).

**6. Funcionalidades Específicas**

* **Feed Principal Personalizado:** El feed de inicio de cada usuario es único.
* **"Samples Similares":** En la página de cada sample, se muestran recomendaciones similares calculadas bajo demanda (con caché) para evitar la sobrecarga del sistema.
* **"Ideas para Tableros":** Calcula un "vector promedio" de los samples en un tablero y recomienda contenido similar a la "esencia" general del tablero, también bajo demanda y con caché.

**7. Plan de Implementación por Fases**

El proyecto se desarrollará en dos fases para garantizar un lanzamiento rápido y una escalabilidad a futuro.

* **Fase 1 (Lanzamiento):**
    * **Objetivo:** Lanzar un sistema de recomendación robusto y eficiente que funcione para los primeros cientos de miles de samples.
    * **Tecnología Clave:** Se implementará toda la arquitectura descrita, utilizando el **pre-filtrado inteligente de PostgreSQL** con índices GIN sobre JSONB para la búsqueda de candidatos en el Proceso Batch. El sistema será 100% funcional y estará preparado para la Fase 2.

* **Fase 2 (Escalado Masivo):**
    * **Objetivo:** Optimizar drásticamente la velocidad de búsqueda de recomendaciones cuando la cantidad de samples supere un umbral crítico (ej. +300,000) y el pre-filtrado de PostgreSQL ya no sea suficiente.
    * **Acción:** Se desarrollará un **microservicio dedicado para Búsqueda Aproximada de Vecinos Cercanos (ANN)**.
    * **Tecnología Clave:** Este microservicio se construirá preferiblemente en **Python** con la librería **Faiss** (o ScaNN).
    * **Integración:** El Proceso Batch de Jophiel dejará de consultar PostgreSQL para la búsqueda de candidatos y en su lugar hará una llamada API a este nuevo microservicio para obtener los resultados de forma casi instantánea. El resto del sistema Jophiel no se modifica.

### Principios Fundamentales de Desarrollo - Reglas Generales

1.  **Código Simple y Legible**
    Prioriza la claridad y la simplicidad por encima de todo. Un código fácil de entender es más fácil de mantener, depurar y evolucionar. Aplica estrictamente el principio DRY (Don't Repeat Yourself) para evitar la redundancia. _Osea por favor archivos pequeños con responsabilidades unicas_

2.  **Responsabilidad Única (SRP)**
    Cada componente (clase, función, módulo) debe tener una única razón para cambiar. Esto crea un sistema modular, más fácil de probar y menos propenso a que un cambio genere errores en cascada.

3.  **Estructura Lógica y Jerárquica**
    La organización de los archivos debe reflejar la arquitectura de la aplicación. Una estructura clara e intuitiva permite a los desarrolladores navegar el proyecto y entender la relación entre sus componentes rápidamente. _Osea clases o archivo con diferentes niveles de responsabilidad no deben ir al mismo nivel_

4.  **Estándares de Código Estrictos**
    Define una guía de estilo única (nomenclatura, formato, etc.) y aplícala de forma automatizada siempre que sea posible. La consistencia en el código reduce la carga cognitiva y elimina las discusiones estilísticas.

5.  **Pruebas Automatizadas como Requisito**
    Considera las pruebas una parte integral de la funcionalidad, no un añadido posterior. Un buen conjunto de pruebas garantiza que el sistema funciona como se espera y permite refactorizar o añadir cambios futuros con confianza.

6.  **Comentarios para el "Porqué", no para el "Qué"**
    El código debe ser tan claro que se explique por sí mismo. Reserva los comentarios exclusivamente para justificar decisiones de diseño complejas o soluciones no evidentes que un futuro desarrollador necesitaría entender.

7.  **Diseño Desacoplado y Basado en Contratos**
    Diseña componentes que interactúen a través de interfaces o APIs bien definidas (contratos). Esto reduce la dependencia entre ellos, permitiendo que sean modificados o reemplazados de forma segura sin afectar el resto del sistema. _Piensalo como piesas de lego_

8.  **Logging Estratégico y Estructurado**
    Implementa un sistema de logs desde el inicio como una característica fundamental. Utiliza logs estructurados (ej. en formato JSON) con niveles de severidad claros para hacer el sistema observable y facilitar drásticamente la depuración. _Cada funcionalidad debe tener un archivo de log por separado, siempre un log central que agrupe todo_

# Notas especificas para este proyecto

1.  No hay datos para testear, se deben generar datos de test.
2.  El proyecto se hara especificamente para una aplicación, pero esto no significa que el codigo deba hacerse especificamente para esta aplicación, debe mantenerse la generalidad y agnosticimo absoluto para que el proyecto pueda funcionar en donde sea. 
3.  Estricta medición de tiempo, hay que llevar un registro de tiempo detallado, ejemplo cuanto tarda en cada proceso, cuando tarda en completarse cada 10, 100, 1000 samples, etc. 
4.  Crear un archivo instalacion para las tablas que se van a usar.

# Guia para gemini 

1. Entrega archivos o metodos completo de principio a fin cuando se haga un cambio.
2. Comunicate en español.
3. Actualiza la lista de tareas cuando sea necesario, entrega al final la lista tareas y toma en cuenta la lluvia de ideas si hay. No regreses status.md completo, solo la parte de lista tareas. 

---
# **Lista de Tareas**

[x] ~~Empezando el proyecto desde cero.~~
[x] **Setup Inicial:** Configurar el entorno `.env`, la conexión a la base de datos (PostgreSQL) y las dependencias base (`illuminate/database`).
[x] **Base de Datos:** Crear el script de instalación `database/install.php` con el esquema SQL para todas las tablas requeridas.
[x] **Modelos de Datos:** Crear los modelos de Eloquent para todas las tablas.
[x] **Logging:** Implementar un `LogHelper` básico y configurar los canales de logging en `config/log.php`.
[ ] **Generador de Datos de Prueba:** Crear un script para poblar las tablas con datos de prueba (usuarios, samples, interacciones).
[ ] **Componente de Vectorización:** Implementar la lógica para consumir la metadata de `casiel` y generar/almacenar los `sample_vectors`.
[ ] **Proceso Batch (Fase 1 - MVP):** Desarrollar el proceso principal que:
    - [ ] Lee las nuevas interacciones de `user_interactions`.
    - [ ] Actualiza los perfiles de gusto en `user_taste_profiles`.
    - [ ] Calcula las recomendaciones (`ScoreFinal`).
    - [ ] Almacena los resultados en `user_feed_recommendations`.
[ ] **Medición de Rendimiento:** Integrar un sistema simple de benchmarking para medir los tiempos de ejecución del Proceso Batch.

---
# **Lluvia de Ideas**

[x] ~~Preparar una funcion helper para los logs, preparar el instalador de la tabla, el env, etc. Limpiar cosas innecesarias de workerman si las hay.~~
[ ] Definir la estructura exacta del `vector` y si se usará una extensión de PostgreSQL como `pgvector`. Para la Fase 1, se puede simular con `TEXT` o `JSONB`. (No se, lo que sea mejor, los datos que guardan jsonb en sword)
[x] ~~Crear modelos de Eloquent para cada una de las tablas de la base de datos para facilitar la interacción.~~
[ ] Diseñar una clase `ScoreCalculator` para encapsular la fórmula del `ScoreFinal` y mantenerla aislada y fácil de probar.