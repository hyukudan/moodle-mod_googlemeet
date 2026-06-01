# Revision UX/UI de controles de grabaciones

Material revisado: capturas `/tmp/ux_list.png`, `/tmp/ux_list_top.png`, `/tmp/ux_cards.png`, JSON computado `/tmp/ux_styles.json` y CSS `styles.css`.

## 1. Colores

### ALTA - Estado activo de Vista parece deshabilitado

**Problema.** El toggle activo de Vista queda con `color: rgb(150,150,150)` sobre `rgb(18,34,56)`. Aunque el contraste numerico puede pasar AA, visualmente comunica "disabled" y rompe el patron de Ordenar/CTA, donde el estado activo usa texto blanco. Para alumnos y profesores el estado seleccionado debe leerse de un golpe.

**Cambio CSS exacto.**

```css
#googlemeet_recordings .googlemeet-view-toggle .btn.btn-primary.active,
#googlemeet_recordings .googlemeet-view-toggle .btn.btn-primary.active:hover,
#googlemeet_recordings .googlemeet-view-toggle .btn.btn-primary.active:focus {
    background-color: #1E3A5F;
    border-color: #1E3A5F;
    color: #FFFFFF !important;
}

#googlemeet_recordings .googlemeet-view-toggle .btn.btn-primary.active img {
    filter: brightness(0) invert(1);
}
```

### MEDIA - Hay demasiados azules principales

**Problema.** Conviven al menos tres azules para acciones primarias o estados activos: `#1E3A5F` en Ordenar/CTA, `#122238` en Vista activo y `#0051F9` en "Recibir avisos". La paleta no esta rota, pero la jerarquia se vuelve menos clara: Vista parece mas oscuro/importante que el CTA, y "Recibir avisos" usa un azul mucho mas saturado que atrae demasiado para una accion secundaria.

**Decision.** Unificar el primario oscuro en `#1E3A5F` para CTA, Ordenar activo y Vista activo. Usar `#174EA6` como azul de enlaces/acento secundario. Evitar `#122238` en controles; reservarlo, si se mantiene, para fondos muy oscuros fuera de esta barra.

**Cambio CSS exacto.**

```css
#googlemeet_recordings .btn-primary,
#googlemeet_recordings .googlemeet-card-cta {
    background-color: #1E3A5F;
    border-color: #1E3A5F;
    color: #FFFFFF;
}

#googlemeet_recordings .btn-primary:hover,
#googlemeet_recordings .googlemeet-card-cta:hover {
    background-color: #16304F;
    border-color: #16304F;
    color: #FFFFFF;
}

#googlemeet_recordings .btn-outline-primary {
    border-color: #174EA6;
    color: #174EA6;
    background-color: #FFFFFF;
}

#googlemeet_recordings .btn-outline-primary:hover {
    background-color: #E8F0FE;
    border-color: #174EA6;
    color: #174EA6;
}
```

### BAJA - Badge "Analisis IA" necesita borde sutil

**Problema.** En tarjeta, "Analisis IA" usa texto `#174EA6` sobre blanco translucido sin borde. Sobre media azul se lee bien; sobre fondos blancos de lista puede parecer texto suelto dentro de una pastilla invisible.

**Cambio CSS exacto.**

```css
.googlemeet-card-aibadge {
    background: #FFFFFF;
    color: #174EA6;
    border: 1px solid #C6DAFC;
}
```

### BAJA - Badge "Nueva" esta bien pero va justo de contraste

**Problema.** `#1E8E3E` con blanco es correcto para chip pequeno, pero en 11px/600 esta cerca del limite perceptivo. No hace falta cambiarlo si se mantiene el peso 600.

**Cambio CSS exacto opcional.**

```css
.googlemeet-card-newbadge {
    background: #188038;
    color: #FFFFFF;
}
```

## 2. Posiciones y agrupacion

### MEDIA - Ordenar y Vista parecen dos grupos equivalentes de ordenacion

**Problema.** "Ordenar por" y "Vista" estan bien en la misma fila porque ambos son preferencias de visualizacion. No moveria Vista a la fila de busqueda: esa fila debe quedarse para encontrar/filtrar contenido. El problema es que ambos grupos se ven casi identicos, asi que Vista compite con Ordenar y puede leerse como otro filtro.

**Decision.** Mantener el orden: `Recibir avisos | Ordenar por | Vista`. Pero hacer que Vista sea un control mas discreto: outline neutro para inactivo, activo azul unificado, menor separacion interna y sin competir con el CTA. "Recibir avisos" debe quedar como accion secundaria de suscripcion, no como parte del grupo de preferencias.

**Cambio CSS exacto.**

```css
.googlemeet-recordings-controls {
    gap: 1rem;
    flex-wrap: wrap;
}

.googlemeet-recordings-controls > form {
    margin-right: 0.5rem !important;
}

.googlemeet-recordings-controls .btn-group:not(.googlemeet-view-toggle) .btn {
    min-height: 40px;
}

.googlemeet-view-toggle .btn {
    min-height: 40px;
    padding-left: 0.65rem;
    padding-right: 0.65rem;
}

.googlemeet-view-toggle .btn.btn-outline-secondary {
    background-color: #FFFFFF;
    border-color: #DADCE0;
    color: #5F6368;
}

.googlemeet-view-toggle .btn.btn-outline-secondary:hover {
    background-color: #F8F9FA;
    border-color: #B8C0CC;
    color: #3C4043;
}
```

### MEDIA - Select de temas desentona con los botones

**Problema.** El select aparece a `45px`, `16px`, borde `2px`, mientras los botones son `40px`, `12px`, borde `1px`. En Moodle puede tolerarse una diferencia, pero aqui rompe la barra: el select parece un campo de formulario grande de otra pantalla.

**Cambio CSS exacto.**

```css
.googlemeet-recordings-filterbar .form-control-sm,
.googlemeet-recordings-filterbar .form-select-sm {
    height: 40px;
    min-height: 40px;
    font-size: 0.875rem;
    line-height: 1.2;
    border: 1px solid #DADCE0;
    border-radius: 8px;
    background-color: #FFFFFF;
    color: #3C4043;
}

.googlemeet-recordings-filterbar .btn-sm {
    min-height: 40px;
    border-radius: 8px;
}
```

### BAJA - Fila superior demasiado pegada a la fila de busqueda

**Problema.** En las capturas la barra superior queda muy cerca de la fila de busqueda/filtro bajo la navegacion Moodle. Conviene dar un poco mas de aire vertical para separar preferencias de filtros.

**Cambio CSS exacto.**

```css
.googlemeet-recordings-header {
    row-gap: 0.75rem;
}

.googlemeet-recordings-filterbar {
    margin-top: 0.25rem;
}
```

## 3. Iconos de accion en lista

### MEDIA - El toggle IA pesa mas que Drive/ocultar/editar

**Problema.** En lista hay una jerarquia clara: CTA "Abrir clase" es primario; Drive, ocultar y editar son acciones secundarias. El toggle IA, al ser circulo azul relleno, parece una segunda accion primaria y pesa mas que los iconos fantasma de al lado. En tarjetas tambien atrae la mirada mas que el CTA cuando hay varios elementos alineados.

**Decision.** Homogeneizar el toggle IA como icono secundario. Usar relleno azul solo al pasar el raton o cuando el panel esta expandido, no por el simple hecho de que exista analisis IA. La disponibilidad de IA ya esta indicada por el badge "Analisis IA".

**Cambio CSS exacto.**

```css
.googlemeet-recording-actions .googlemeet-drive-link,
.googlemeet-recording-actions .recordinghowhide,
.googlemeet-recording-actions .recordingeditname,
.googlemeet-recording-actions .googlemeet-ai-edit-btn,
.googlemeet-recording-actions .googlemeet-ai-toggle-btn {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    color: #52627A;
    background: transparent;
    border: 1px solid transparent;
}

.googlemeet-recording-actions .googlemeet-ai-toggle-btn.googlemeet-ai-toggle-active {
    background: transparent;
    color: #52627A;
}

.googlemeet-recording-actions .googlemeet-ai-toggle-btn:hover,
.googlemeet-recording-actions .googlemeet-ai-toggle-btn.expanded {
    background: #E8F0FE;
    border-color: #C6DAFC;
    color: #174EA6;
    transform: none;
    box-shadow: none;
}
```

## 4. Otros problemas de contraste, espaciado y alineacion

### MEDIA - Selector CSS de activos demasiado generico para medir y mantener

**Problema.** El JSON muestra que `.googlemeet-recordings-controls .btn-group .btn.active` captura el activo de Vista, no necesariamente el de Ordenar. Eso indica que el CSS/medicion no distingue entre grupos. Para mantenimiento, Ordenar necesita una clase propia; si no, cualquier regla sobre `.btn-group` afectara ambos.

**Cambio recomendado de markup y CSS.**

En `templates/recordingstable.mustache`, cambiar:

```html
<div class="btn-group btn-group-sm" role="group">
```

por:

```html
<div class="btn-group btn-group-sm googlemeet-sort-toggle" role="group">
```

Y usar:

```css
.googlemeet-sort-toggle .btn.btn-primary {
    background-color: #1E3A5F;
    border-color: #1E3A5F;
    color: #FFFFFF;
}

.googlemeet-sort-toggle .btn.btn-outline-secondary {
    background-color: #FFFFFF;
    border-color: #DADCE0;
    color: #5F6368;
}
```

### BAJA - Chip de fecha con capitalizacion inconsistente

**Problema.** El CSS fuerza `text-transform: capitalize`, por eso aparece `Lun. 25 May`. En espanol, dentro de una fila compacta, conviene `lun. 25 may` o incluso `25 may`. Como el grupo ya dice `Mayo 2026`, la opcion mas limpia para escaneo es `25 may`; si se quiere conservar dia de semana, usar minusculas.

**Decision.** Para lista: `lun. 25 may` si el dia de semana aporta orientacion; `25 may` si se quiere reducir ruido. No usar capitalizacion artificial.

**Cambio CSS exacto.**

```css
.googlemeet-listitem-date {
    text-transform: none;
}
```

Si el PHP devuelve mayusculas, corregir en la generacion de `createddateshort`; CSS no puede convertir de forma fiable a minusculas en todos los idiomas.

### BAJA - Chip de fecha puede bajar protagonismo

**Problema.** El chip de fecha azul claro se lee bien, pero en lista compite con los badges y con el CTA. Puede mantenerse; si se quiere una lista mas serena, bajar saturacion.

**Cambio CSS exacto opcional.**

```css
.googlemeet-listitem-date {
    background: #F1F5FB;
    color: #174EA6;
}
```

## Lista priorizada de cambios

1. Corregir Vista activo: `#1E3A5F` + texto `#FFFFFF !important`.
2. Unificar azul primario de CTA, Ordenar activo y Vista activo en `#1E3A5F`; reservar `#174EA6` para enlaces/acento.
3. Bajar el toggle IA a accion secundaria: transparente por defecto, azul claro solo hover/expandido.
4. Igualar altura/tipografia de busqueda, select y boton a `40px`, `0.875rem`, borde `1px`.
5. Anadir clase `.googlemeet-sort-toggle` al grupo de Ordenar para evitar reglas genericas sobre `.btn-group`.
6. Quitar `text-transform: capitalize` del chip de fecha y preferir `lun. 25 may` o `25 may`.
7. Anadir borde `#C6DAFC` al badge "Analisis IA" para que no se pierda sobre blanco.
