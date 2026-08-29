# Movie AI Workspace

**Convierte tu propio servidor de IA en un estudio de cine privado en el que todo el equipo puede entrar desde cualquier lugar: guion, storyboards, planos y material, todo el flujo en tus propias máquinas y con solo un navegador.**

[English](README.md) · [中文](README.zh-CN.md) · [日本語](README.ja.md)

![License: MIT](https://img.shields.io/badge/license-MIT-22c55e)
![Self-hosted](https://img.shields.io/badge/IA-autohospedada-7c3aed)
![Workspace](https://img.shields.io/badge/workspace-Codex%20%2B%20tmux-2563eb)
![Media](https://img.shields.io/badge/vídeo-MiniMax%20H3-f97316)

> **Empieza aquí: entrega este repositorio a Codex.** Después de leer este README, `AGENTS.md` y la guía de instalación enlazada, Codex puede inventariar tus servidores de IA reales y ayudarte a construir todo el sistema: web de reservas y consola administrativa, Workspaces aislados en el navegador, plano de control PostgreSQL/Redis, sesiones con planes de IA personales o corporativos, enrutamiento a modelos locales y externos, GPU Workers, workflows MiniMax H3, límites de seguridad y pruebas de aceptación de extremo a extremo. Tú aportas las máquinas, los datos reales de red, las cuentas y el acceso administrativo autorizado; la documentación entrega a Codex la arquitectura y los contratos ejecutables para adaptar, instalar, verificar y mantener el despliegue.

> **Usa primero los planes de IA que el equipo ya paga y evita volver a pagar por la API de imagen.** Con cinco usuarios que ya tienen ChatGPT Pro 20x y el volumen documentado de 10.000 generaciones/ediciones mensuales de fondos y storyboards, esta arquitectura puede evitar aproximadamente **410–1.650 USD al mes**, o **4.920–19.800 USD al año**, sólo en salidas de GPT-Image-2, además de los tokens de entrada de las ediciones. La cifra todavía **no incluye** tokens de modelos de texto, razonamiento del agente, herramientas o contexto largo de un CLI impulsado íntegramente por API. Y, sobre todo, cada creador inicia el CLI con su propia cuenta ChatGPT: la actividad Codex del lado de OpenAI queda regida por esa cuenta y sus controles de datos, mientras historial local, proyectos y credenciales permanecen en su Workspace persistente y aislado. Más abajo se detallan los supuestos y límites.

El servidor se queda en el estudio, en la sala de máquinas o en un rincón de casa; tú —en el set, en casa o en un tren— abres el navegador, inicias sesión, y tus proyectos, tu IA y tus planos a medio generar están exactamente donde los dejaste.

## Para cineastas: esto no es otra web de IA

(¿No eres técnico? Con esta sección basta.)

Si haces cine con IA, conoces la rutina: el guion vive en una ventana de chat, el arte conceptual sale de otra web, el vídeo se renderiza en una tercera plataforma y el material queda repartido entre nubes y chats. Cada plataforma cobra por separado, hace cola por separado y modera por separado; a mitad de un render aparece “contenido no permitido”, cuando solo escribías el monólogo de un villano.

Movie AI Workspace propone otro camino: **en lugar de alquilar un escritorio en la plataforma de otros, convierte tu propia máquina en el estudio.** Es un proyecto de código abierto que transforma un servidor con GPU en una plataforma completa de creación audiovisual para ti y tu equipo: escritura con IA, imagen, generación de vídeo y gestión de material, todo en un mismo lugar y dentro de un mismo proyecto.

### Un servidor se convierte en todo un estudio

- **Una sala de guionistas que nunca cierra.** La IA te ayuda a desglosar el guion, pulir diálogos, cuidar la continuidad y traducir, con modelos desplegados por ti. Terror, crimen, guerra, intimidad: los temas normales de la ficción no se rechazan a mitad de escena.
- **Una mesa de conceptos donde probar es gratis.** Arte conceptual, storyboards, exploración de estilo: itera con modelos de imagen locales tantas rondas como quieras sin contar créditos.
- **Un plató que entrega planos.** Mediante los workflows fijos de MiniMax H3: texto a vídeo, imagen a vídeo, primer/último fotograma y generación por referencia; los planos terminados entran directos a la biblioteca de medios del proyecto.
- **Una biblioteca de medios organizada por proyecto.** Un proyecto por película: guion, referencias, generaciones y material final juntos, en vez de dispersos por todas partes.

### Unas pocas máquinas, organizadas para todo el equipo

Los servidores de IA son caros y es muy improbable que tengas uno por persona. El problema real nunca fue “cuántos compramos”, sino **cómo unas pocas máquinas mantienen a todo el mundo trabajando**.

La mayoría de los equipos lo resuelve gritando en el chat —“¿alguien está en la 2?”— y luego llega la historia de siempre: dos personas arrancan a la vez y revientan la VRAM, a alguien le matan el trabajo a medias, otro se va a comer dejando la máquina ocupada y, lo más frecuente, nadie se entera de que un servidor estuvo libre toda la tarde.

Este sistema convierte las GPU en un recurso que se reserva como una sala de reuniones:

- **Ver de un vistazo qué está libre.** Consulta la agenda de cada servidor por fecha: quién lo tiene, hasta cuándo y qué franjas quedan abiertas. Si una máquina está libre ahora mismo, empieza de inmediato. Las horas se muestran en la zona horaria de cada persona, así que un equipo repartido no hace cálculos mentales.
- **Lo que reservas es tuyo y nadie te lo quita.** Dos personas no pueden tener la misma máquina en la misma franja: lo impone una restricción de exclusión de PostgreSQL en la base de datos, no un pacto de caballeros en la aplicación. Que te corten el trabajo a medias es estructuralmente imposible.
- **Amplía si se alarga.** Si la franja siguiente sigue libre, extiende sobre la marcha en lugar de salir y volver a reservar.
- **La capacidad ociosa vuelve sola.** Los espacios de trabajo inactivos se detienen por su cuenta, y los permisos de modelo privado se recuperan cuando una reserva expira o su titular no aparece. Una máquina no se pasa la noche encendida en vano porque alguien olvidó cerrar sesión.
- **Bloquea el mantenimiento por adelantado.** ¿Actualizar drivers, cambiar modelos, reiniciar servicios? Declara una ventana de mantenimiento y esa franja deja de aceptar reservas. Un nodo también puede ponerse en *vaciado*: no admite nuevas reservas mientras el trabajo en curso termina con seguridad.
- **Varias máquinas, una sola planificación.** Las reservas se asignan a un nodo de cómputo concreto, así que el trabajo de imagen en una y el de vídeo en otra nunca chocan.
- **Todo queda registrado.** Las operaciones clave entran en un registro de auditoría, de modo que planificar y revisar no depende de la memoria ni del historial de chat.

### El servidor se queda en el rack; el estudio viaja contigo

El sistema está pensado para el **uso remoto** desde el primer día. La máquina no se mueve y tú puedes trabajar desde cualquier lugar:

- **Basta un navegador.** Inicia sesión en el portal, reserva tu franja, abre el proyecto y aparece tu mesa de trabajo de siempre, en portátil o tableta.
- **Desconectarse no es interrumpir.** Cierra el portátil y el servidor sigue trabajando; vuelve a entrar y regresas a la misma escena, incluida la conversación a medias con la IA.
- **El trabajo te sigue a ti, no te ata a la máquina.** Deja los planos en cola por la tarde en el estudio y revísalos por la noche desde el sofá; si la idea llega de viaje, conéctate y retoca dos líneas de diálogo.

> Cinco de la tarde, en el estudio: la 3 está libre esta noche, reservas de ocho a once, la IA convierte el storyboard de la escena tres en una lista de planos, envías dos trabajos de vídeo y cierras el portátil.
> Diez de la noche, en casa: abres el navegador y los dos planos ya esperan en la biblioteca. Eliges uno, la IA lo revisa contra los requisitos de entrega y, de paso, reservas la franja de mañana.
> Tu portátil siguió cerrado. El servidor no paró. La película siguió avanzando.

### Y varios dolores conocidos desaparecen

- **Adiós a la ansiedad del pago por uso.** Retoques de diálogo, continuidad, traducciones, fotogramas de concepto: las operaciones que repites cientos de veces al día corren en tus propias GPU, e iterar deja de costar créditos.
- **Adiós al infierno de configurar ComfyUI.** Montar un flujo de imagen serio —con LoRA y aceleración— solía significar noches buscando modelos en HuggingFace y Civitai, conectando nodos, cuadrando versiones y depurando errores durante horas. Aquí esa complejidad la construye Codex siguiendo la guía y queda sellada en workflows fijos del administrador: quien crea solo los invoca, sin tocar un solo nodo.
- **Adiós a preocuparte por dónde viaja tu IP.** Guiones inéditos, biblias narrativas, referencias de casting y montajes preliminares pueden quedarse de principio a fin en tu propio servidor, sin pasar por plataformas de terceros.
- **Adiós a la moderación de talla única.** Las reglas siguen existiendo, pero las pone la producción: quién usa qué, cuándo y para generar qué, dentro de tus propios permisos y aprobaciones; el filtro genérico de una plataforma se sustituye por normas hechas para el cine.

### Siendo honestos

Este proyecto lo construyó su autor en cinco días de tiempo libre, y se nota: hay bordes sin pulir, la documentación sigue creciendo y llevarlo a otro hardware implica trabajo real de adaptación. No es un producto terminado ni se instala solo.

Lo que sí hizo fue resolver un problema real de nuestro pequeño equipo: cómo unos pocos servidores de IA pueden servir a todo el mundo de forma ordenada, sin choques, sin desperdicio y sin tener que estar en la misma sala que el hardware. Si tu situación se parece a la nuestra, esto es al menos un punto de partida que ya funciona y puedes adaptar.

### No soy técnico. ¿Cómo empiezo?

Montar el sistema requiere un compañero técnico o, como recomienda el propio proyecto, un asistente de IA como Codex o Claude siguiendo la guía completa del repositorio. Una vez instalado, quienes crean solo tocan el portal y la mesa de trabajo del navegador. Pasa las secciones siguientes a quien gestione el despliegue, y a rodar.

---

**Lo que sigue está dirigido a perfiles técnicos y operadores: reserva tus propios servidores de IA, abre un espacio persistente de Codex y convierte modelos privados en un estudio creativo seguro para todo el equipo.**

Movie AI Workspace es un plano de control de código abierto para equipos que poseen una o varias máquinas de IA. Reúne reservas, proyectos aislados, identidades persistentes de planes de IA, modelos privados y flujos fijos de imagen y vídeo, sin entregar al usuario una shell del host GPU ni claves del proveedor.

Un miembro reserva el servidor, abre su proyecto, vuelve a la misma sesión tmux, pide a Codex que planifique el trabajo y genera vídeo MiniMax H3 mediante la CLI limitada `movie-ai`.

## Por qué existe

- **Deja de repartir la GPU por chat.** Las reservas, ventanas de mantenimiento y restricciones de PostgreSQL deciden quién puede ejecutar.
- **Usa tu propio plan de IA.** Cada identidad personal de Codex queda aislada; una identidad empresarial administrada puede asignarse sólo a operadores autorizados.
- **Usa modelos privados desde `/model`.** Los alias Qwen 3.8 27B y DeepSeek V4 Flash pasan por un Broker ligado a la reserva.
- **Flujos creativos reproducibles.** La imagen del Workspace incluye skills de MiniMax H3, contratos fijos, bibliotecas de medios y verificación de artefactos.
- **Reconecta y continúa.** El terminal web, tmux y los volúmenes persistentes conservan el contexto.
- **Escala de forma honesta.** La versión actual implementa Portal central + un nodo de ejecución; el procedimiento para añadir nodos de cómputo está en la §8 de la guía de instalación y el modelo de datos multinodo (restricción de exclusión por nodo, registro y health check) se publica como código.

## Componentes

| Capa | Función |
| --- | --- |
| Portal | Laravel/Filament, TOTP, reservas, proyectos, administración y medios |
| Workspace | Codex endurecido, tmux, proyecto e identidad persistentes |
| Model Router | Conserva Codex alojado y enruta sólo modelos privados aprobados |
| AI Broker | Valida la reserva y limita contratos de texto, imagen y vídeo |
| Media Adapter | Conecta flujos aprobados de MiniMax H3 e imagen |
| Host Control | Preflight GPU y acciones fijas mediante Unix Socket |
| Handoff IA | `AGENTS.md`, `CLAUDE.md`, skills administrativos, manual y tests |

```text
Reserva -> proyecto -> identidad personal/empresa -> tmux en el navegador
        -> planificación con Codex/Claude -> modelo privado o movie-ai
        -> Broker fijo -> artefacto verificado en la biblioteca del proyecto
```

## Modelos

- Los modelos alojados de Codex usan la identidad seleccionada al entrar.
- `qwen3.8-27b-uncensored` es un alias configurable para un Qwen privado.
- `deepseek-v4-flash-0731` es un alias configurable para DeepSeek privado/externo.
- Se incluyen contratos locales para Z-Image-Turbo y Hunyuan.
- MiniMax H3 ofrece T2VA, I2VA, FL2VA, L2VA y Ref2VA nativo mediante workflows administrados.

El repositorio no distribuye pesos. “Uncensored” describe un alias proporcionado por el operador; éste es responsable de licencias, política de seguridad y uso legal.

## Por qué los modelos uncensored autohospedados cambian la producción cinematográfica

Codex deja de ser sólo un chat cuando actúa como **cerebro de producción** delante de modelos y GPU controlados por el estudio. Puede descomponer la película, conservar el plan, cargar el skill adecuado, preparar planos, invocar flujos de imagen y MiniMax H3 y comprobar los artefactos. Desde el mismo terminal, `/model` cambia a un endpoint uncensored `deepseek-v4-flash-0731` o a `qwen3.8-27b-uncensored` sin salir del proyecto ni exponer puertos o claves.

La combinación aporta ventajas muy concretas:

- **La sala de guionistas no se detiene.** La ficción legítima incluye terror, crimen, guerra, sátira política, intimidad, diálogos de villanos, transformación corporal y otros temas adultos. Un modelo controlado por el estudio interrumpe menos una escena, no suaviza automáticamente el tono y evita convertir cada idea en una cadena de eufemismos.
- **La propiedad intelectual inédita permanece dentro.** Guiones, biblias narrativas, referencias de casting, storyboards, montajes preliminares y conceptos de clientes pueden quedarse en la red propia. El equipo accede mediante el Broker ligado a la reserva, no mediante claves o endpoints LAN directos.
- **El modelo se dirige como otro miembro del equipo.** El operador elige pesos, cuantización, contexto, prompt de sistema, LoRA, muestreo y momento de actualización. Un modelo puede especializarse en guion y continuidad, otro en razonamiento de planos, mientras Codex alojado conserva planificación, código, herramientas y handoff.
- **Iterar es barato y reproducible.** Otra reescritura, traducción, variante de plano o revisión de continuidad consume capacidad propia en vez de iniciar una nueva negociación por token. Proyectos persistentes, tmux, skills, workflows fijos, seeds y comprobaciones conservan cómo se obtuvo el resultado.
- **La conversación termina en medios reales.** No es sólo una interfaz de chat. La misma sesión gobernada inspecciona medios, invoca un skill cinematográfico, envía un trabajo H3 limitado y devuelve el vídeo verificado a la biblioteca del proyecto.

Para un cineasta, el salto no consiste solamente en que un modelo uncensored responda. Consiste en mantener el mismo cerebro creativo privado unido al proyecto, las herramientas, las referencias, los skills y las aprobaciones humanas desde el tratamiento hasta el plano final. **Uncensored no significa sin gobierno:** el estudio sustituye filtros genéricos de terceros por su propia política legal, permisos, reservas y límites de ejecución impuestos por el Broker.

## Usa el plan ChatGPT de cada creador para generar y editar imágenes

Cada persona que inicia sesión conserva una identidad ChatGPT/Codex separada dentro de un Workspace aislado. Cuando el plan y la superficie Codex de ese usuario ofrecen la herramienta de imagen, Codex puede usar `gpt-image-2` con la cuota de su plan existente para **generar y editar** arte conceptual de fondos, estudios de ambiente, hojas de personajes y storyboards. El estudio no necesita instalar una API key compartida de OpenAI en cada Workspace ni enviar cada iteración visual a una cuenta API con facturación independiente.

Más importante que el ahorro es la propiedad de la cuenta y los datos: cada CLI utiliza el inicio de sesión ChatGPT de su creador y no una identidad API común. El contenido procesado por OpenAI sigue la ruta Codex de ese usuario y queda sujeto a los términos y controles de datos de su cuenta; el historial local de Codex, los archivos del proyecto y las credenciales persisten en el almacenamiento aislado de su Workspace. Una identidad de plan corporativo sigue disponible como opción separada y asignada expresamente. OpenAI también indica que [los workflows locales se ejecutan en el dispositivo y los controles de datos ChatGPT se aplican al contenido procesado por Codex](https://help.openai.com/en/articles/11369540-using-codex-with-chatgpt).

No es un truco contable. OpenAI explica que [las suscripciones ChatGPT y la API se facturan por separado](https://help.openai.com/en/articles/9039756); el proyecto no convierte una suscripción en créditos API. Conserva la sesión personal y usa esa ruta sólo cuando el plan y la interfaz correspondientes ofrecen la herramienta de imagen. Cualquier fallback a la API sigue facturándose como API y cada cuenta continúa sujeta a los límites, términos y controles de abuso vigentes.

**Ejemplo transparente para un estudio de cinco personas (precios comprobados el 28-08-2026):** suponemos que las cinco ya pagan el nivel [ChatGPT Pro 20x de 200 USD al mes](https://help.openai.com/en/articles/9793128) y que cada una realiza 100 generaciones o ediciones de una imagen durante 20 días laborables. El total es `5 × 100 × 20 = 10.000` imágenes al mes. La [tabla oficial de costes de GPT-Image-2](https://developers.openai.com/api/docs/guides/image-generation#token-usage-and-costs) fija una salida 1536×1024 en 0,041 USD con calidad Medium y 0,165 USD con calidad High:

| El mismo trabajo enviado íntegramente a la API | Coste API de salida evitado |
| --- | ---: |
| 10.000 generaciones/ediciones Medium horizontales | **unos 410 USD/mes** o 4.920 USD/año |
| 10.000 generaciones/ediciones High horizontales | **unos 1.650 USD/mes** o 19.800 USD/año |

La edición también cobra tokens de imagen y texto de entrada, por lo que la alternativa API puede costar más que estas cifras de sólo salida. La estimación omite deliberadamente los tokens API de conversaciones, razonamiento del agente, herramientas, recuperación y contexto largo que consumiría el mismo flujo de producción; la factura API total evitada puede ser mayor. Las cinco suscripciones existentes cuestan 1.000 USD mensuales en total; no se restan porque el supuesto indica que el equipo ya las posee. Si se contratan exclusivamente para este despliegue, hay que restarlas al calcular el ahorro neto. “20x” es un nivel del plan, no la garantía de veinte veces una cantidad fija de imágenes. El ahorro real es el trabajo que cabe en las cuotas existentes multiplicado por el precio API vigente.

## Una implementación de referencia con criterios propios, no un instalador universal

Este proyecto nació del interés personal de su autor y se implementó para un conjunto concreto de servidores de IA reales. Es una referencia de ingeniería con contratos ejecutables, no un instalador comercial abstraído para cualquier rack, GPU, hipervisor, almacenamiento, firewall, servidor de modelos o sistema de identidades.

La documentación prefiere una topología de referencia explícita y auditable a una portabilidad imaginaria. Las unidades systemd, sockets, redes Compose, funciones de nodo, transiciones GPU y supuestos operativos describen el sistema usado durante el desarrollo. **No están optimizados para todas las distribuciones de servidores**, y copiar los comandos sin cambios a otro hardware no constituye una estrategia de despliegue.

Para adoptar el proyecto, usa Codex como ingeniero de portabilidad. Pídele que lea `AGENTS.md` y la guía de instalación, inventaríe tus máquinas reales y prepare un mapeo consciente de:

- ubicación de Portal, Workspace, Broker, Adapter, Worker y servidores de modelos;
- GPU, runtimes, transiciones de VRAM, almacenamiento y volúmenes persistentes;
- CIDR LAN, DNS, TLS, firewall, Unix Sockets, túneles SSH y salida de red;
- propiedad de systemd/Compose, usuarios, secretos, copias y recuperación;
- alias de modelos, compatibilidad API, contexto y pruebas de aceptación.

El producto reutilizable es la arquitectura, sus fronteras de seguridad y el método de handoff a la IA. Los valores concretos son ejemplos. Quedan bordes ásperos y trabajo dependiente del entorno; cada operador debe validar el resultado en su propia infraestructura.

## Convierte esta referencia en tu propia plataforma interna de IA

Sí. Este repositorio puede adaptarse a la infraestructura de un cliente para ofrecer el mismo tipo de sistema: portal de reservas y consola administrativa, Workspaces aislados por usuario, bibliotecas de medios por proyecto, sesiones de Codex/Claude con un plan corporativo compartido o cuentas personales, enrutamiento a modelos locales y externos y workflows de producción con MiniMax H3. Codex u otro agente de ingeniería puede usar el repositorio como especificación ejecutable en lugar de reconstruir todos estos contratos desde cero.

Un despliegue para un cliente puede cubrir el recorrido completo:

- inventariar servidores, GPU, VRAM, redes, almacenamiento, runtimes de modelos, límites de identidad y restricciones operativas;
- ubicar y configurar Portal, Gateway, PostgreSQL, Redis, Manager, Router, Broker, adaptadores, servicios de modelos y uno o más Compute Workers;
- conectar modelos locales, endpoints externos compatibles con OpenAI, nodos personalizados de ComfyUI, MiniMax H3 y otros workflows multimedia aprobados por el administrador;
- configurar reservas de GPU, secretos por nodo, manifests de modelos, cuentas personales, planes de IA corporativos, sesiones tmux, Skills y archivos de handoff para agentes;
- establecer DNS, TLS, SMTP, firewall, salida de red, copias, recuperación y propiedad de servicios con privilegios mínimos; y
- entregar `AGENTS.md`, `CLAUDE.md`, contexto de servidores, runbooks y pruebas de aceptación específicos del cliente para que otra sesión de Codex o Claude pueda continuar con seguridad.

El cliente u operador debe aportar un inventario preciso de hardware y red, la lista prevista de modelos y runtimes, requisitos de dominio e identidad, reglas de equipo y reservas, necesidades de almacenamiento y un método autorizado de instalación administrativa. Las API keys y contraseñas **no deben pegarse en una conversación con la IA**: deben generarse o instalarse directamente en los servidores mediante archivos de secretos ignorados por Git o el gestor de secretos del cliente.

La implementación actual está optimizada para Linux x86_64, Docker Compose, systemd, PostgreSQL, Redis y servidores de IA conectados por LAN. ARM, Kubernetes, flotas de GPU en la nube, varias VLAN, redes zero-trust, SSO u otros servidores de modelos son destinos válidos, pero requieren una adaptación explícita y no una sustitución masiva de la topología de ejemplo. El despliegue sólo está terminado cuando un usuario real puede reservar un nodo GPU válido, entrar en el Workspace correctamente aislado, invocar el modelo local o externo elegido, ejecutar un trabajo multimedia aprobado y recuperar el artefacto verificado; no basta con que los contenedores aparezcan como Healthy.

## Inicio rápido

```bash
git clone https://github.com/linkprint/local-ai-movie-workspace.git movie-ai
cd movie-ai
sh ops/bootstrap.sh
```

Edita `.env` y `env/laravel.env` (ignorados por Git), instala AppArmor, Host Control y los sockets de modelo, y después:

```bash
docker compose build
docker compose --profile workspace-build build movie-workspace-image
docker compose up -d movie-postgres movie-redis
docker compose run --rm --no-deps movie-web php artisan migrate --force
docker compose run --rm --no-deps movie-web php artisan db:seed --force
docker compose up -d
docker compose exec movie-web php artisan movie:create-admin \
  --name="Initial Administrator" --email="admin@example.com" --timezone="UTC"
```

Configura un transporte SMTP real antes del último comando. Se envía un enlace
de configuración de contraseña de un solo uso; nunca se crea ni envía una
contraseña predeterminada en texto claro.

Las migraciones contienen el esquema PostgreSQL completo. El seeder público
solo repone plantillas saneadas de nodos y el singleton de arrendamiento de
Codex empresarial; no crea usuarios, reservas, proyectos, sesiones, trabajos,
auditorías ni medios.

Sigue la [guía completa de instalación, operación y handoff para IA](docs/AI_INSTALL_AND_OPERATIONS_GUIDE.md). Explica modelos locales y remotos, puentes para proveedores externos, MiniMax H3, identidades de Codex, tmux, skills, copias de seguridad, pruebas y publicación.

## Skills verificables

Los skills administrativos se copian a `/etc/codex/skills`, el ámbito administrativo oficial de Codex. El arranque ejecuta:

```bash
movie-ai skills verify
```

El Workspace falla de forma segura si falta metadata o los archivos son escribibles. Usa `/skills` para la aceptación y `$skill-name` de forma explícita en flujos críticos.

## Seguridad

El objetivo no es afirmar simplemente que «autohospedado es seguro», sino limitar el radio de impacto: permitir trabajo real sin convertir cada sesión del navegador en una puerta de administración.

- **Workspace de privilegio mínimo.** Los contenedores usan usuario sin privilegios, raíz de sólo lectura, capabilities eliminadas, `no-new-privileges`, tmpfs, seccomp, AppArmor, redes internas y salida controlada. Los atajos `privileged`, `SYS_ADMIN` y `seccomp=unconfined` quedan fuera del diseño soportado.
- **Capacidades concretas en lugar de una shell.** El Workspace no recibe Docker, SSH, systemd, Host Control Socket, puertos LAN ni workflows arbitrarios de ComfyUI. Las acciones revisadas usan contratos Unix Socket estrechos; los trabajos multimedia pasan por la CLI `movie-ai` y schemas limitados del Broker.
- **La reserva también autoriza.** Grants firmados y breves vinculan usuario, proyecto, nodo, reserva y vencimiento. El Broker mantiene estado y revocación de Jobs; restricciones de exclusión PostgreSQL impiden propiedad solapada. Vencimiento, ausencia, cancelación o abandono retiran el permiso del modelo.
- **Sesiones creativas sin claves de proveedor.** Las credenciales permanecen en secrets legibles por root, un Secret Manager o un Adapter administrado. Los modelos llegan al Broker mediante sockets fijos y el Workspace sólo obtiene la API limitada por reserva.
- **Identidades y proyectos aislados.** Los planes personales y corporativos usan volúmenes distintos; cada usuario y proyecto conserva por separado estado y medios. Colaborar no exige copiar credenciales ni el historial CLI de otra persona.
- **La topología queda detrás del plano de control.** Las APIs de usuario no devuelven IP de nodo, URL internas del Broker, detalles internos de salud ni HMAC Secrets. El Egress controlado dificulta usar un Workspace comprometido como pivote de red.
- **Autenticación y evidencia.** El Portal admite roles y TOTP; las operaciones críticas de reserva, administración y Runtime quedan registradas.
- **Publicación con gates.** Git no contiene Provider Keys, claves privadas, contraseñas, `auth.json`, medios de usuarios, registros de producción ni dumps. Se publican migrations completas y bootstrap saneado, con escaneo automático de árbol e historial.

Es una arquitectura de referencia orientada a seguridad y con gates automatizados, no una afirmación de certificación formal ni de pentest independiente. El host Docker y sus administradores siguen siendo raíces de confianza; cada despliegue debe validar TLS, firewall y egress, rotación de secrets, parches, copias, recuperación y el tratamiento de datos de proveedores externos.

## Estado real

El Portal central y el camino de un nodo están implementados. El esquema multinodo, el registro de nodos y los health checks se publican como código, y la §8 de la guía de instalación cubre cómo añadir un Worker; pero el enrutado del Worker y sus gates de fallo no han pasado una validación real con varias máquinas, así que no anunciamos planificación multinodo de producción. El README no promete una función que aún no existe.

## Desarrollo asistido por IA

Codex lee [AGENTS.md](AGENTS.md) y Claude Code recibe [CLAUDE.md](CLAUDE.md). Ambos remiten al manual canónico y mantienen la frontera `Portal -> Manager -> Broker -> Adapter`.

## Validación

```bash
python3 -m unittest discover -s ops/tests -p 'test_*.py'
sh ops/tests/gate4-static.sh
python3 ops/tests/public_release_scan.py --tree
```

Se aceptan issues y pull requests enfocados. Mantén las fronteras de seguridad, añade tests y no envíes pesos ni credenciales.

Licencia MIT. Consulta [LICENSE](LICENSE).
