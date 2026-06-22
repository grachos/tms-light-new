/* Light TMS - JS de UI: autocompletado de municipio y mapa lat/long */

(function () {
    'use strict';

    function escHtml(s) {
        if (typeof s !== 'string') return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    /* ---------- Autocompletado genérico (municipios, terceros, vehículos) ----------
       Uso en HTML:
         <div class="autocompletar" data-ac="terceros" data-ac-params="solo_conductor=1">
           <input class="ac-texto" ...>
           <ul class="ac-lista"></ul>
           <input type="hidden" name="..." data-ac-field="tipo_id">
           <input type="hidden" name="..." data-ac-field="num_id">
         </div>
       El endpoint ?r=<ac>.buscar&q=... devuelve items con 'label' + los campos
       que se copian a los hidden según data-ac-field.                              */
    function initAutocomplete() {
        document.querySelectorAll('[data-ac]').forEach(function (caja) {
            const endpoint = caja.getAttribute('data-ac');
            const extra    = caja.getAttribute('data-ac-params') || '';
            const texto    = caja.querySelector('.ac-texto');
            const lista    = caja.querySelector('.ac-lista');
            const hiddens  = caja.querySelectorAll('[data-ac-field]');
            let timer = null;

            function limpiarHidden() {
                hiddens.forEach(function (h) { h.value = ''; });
            }

            texto.addEventListener('input', function () {
                limpiarHidden(); // inválido hasta elegir de la lista
                const q = texto.value.trim();
                clearTimeout(timer);
                if (q.length < 2) { lista.innerHTML = ''; return; }
                timer = setTimeout(function () { buscar(q); }, 220);
            });

            texto.addEventListener('blur', function () {
                setTimeout(function () { lista.innerHTML = ''; }, 180);
            });

            function buscar(q) {
                const url = '?r=' + endpoint + '.buscar&q=' + encodeURIComponent(q) + (extra ? '&' + extra : '');
                fetch(url)
                    .then(function (r) { return r.json(); })
                    .then(pintar)
                    .catch(function () { lista.innerHTML = ''; });
            }

            function pintar(items) {
                lista.innerHTML = '';
                items.forEach(function (it) {
                    const li = document.createElement('li');
                    if (it.nombre_mpio && it.nombre !== it.nombre_mpio) {
                        li.innerHTML = '<strong>' + escHtml(it.nombre) + '</strong><br><small>' + escHtml(it.nombre_mpio) + ', ' + escHtml(it.departamento) + '</small>';
                    } else {
                        li.textContent = it.label;
                    }
                    li.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        texto.value = it.label;
                        hiddens.forEach(function (h) {
                            h.value = it[h.getAttribute('data-ac-field')] || '';
                        });
                        lista.innerHTML = '';
                        caja.dispatchEvent(new CustomEvent('ac:select', { detail: it }));
                    });
                    lista.appendChild(li);
                });
            }
        });
    }

    /* ---------- Mapa para lat/long (Leaflet + OpenStreetMap) ---------- */
    function initMapa() {
        const cont = document.getElementById('mapa');
        if (!cont || typeof L === 'undefined') { return; }

        const inLat = document.getElementById('latitud');
        const inLon = document.getElementById('longitud');
        const lat0 = parseFloat(inLat.value) || 4.6097;   // Bogotá por defecto
        const lon0 = parseFloat(inLon.value) || -74.0817;

        const map = L.map('mapa').setView([lat0, lon0], inLat.value ? 15 : 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(map);

        let marker = inLat.value
            ? L.marker([lat0, lon0], { draggable: true }).addTo(map)
            : null;

        function fijar(lat, lon) {
            inLat.value = lat.toFixed(8);
            inLon.value = lon.toFixed(8);
            if (marker) { marker.setLatLng([lat, lon]); }
            else {
                marker = L.marker([lat, lon], { draggable: true }).addTo(map);
                marker.on('dragend', function () {
                    const p = marker.getLatLng();
                    fijar(p.lat, p.lng);
                });
            }
        }

        map.on('click', function (e) { fijar(e.latlng.lat, e.latlng.lng); });
        if (marker) {
            marker.on('dragend', function () {
                const p = marker.getLatLng();
                fijar(p.lat, p.lng);
            });
        }

        // Buscar dirección (Nominatim, gratis)
        const btn = document.getElementById('mapa-buscar-btn');
        const inp = document.getElementById('mapa-buscar');
        if (btn && inp) {
            btn.addEventListener('click', function () {
                const q = inp.value.trim();
                if (!q) { return; }
                fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=co&q=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.length) {
                            const lat = parseFloat(res[0].lat), lon = parseFloat(res[0].lon);
                            map.setView([lat, lon], 16);
                            fijar(lat, lon);
                        } else {
                            alert('No se encontró la dirección.');
                        }
                    });
            });
        }

        // Pegar enlace de Google Maps (o "lat,lng") y extraer coordenadas
        function extraerCoords(s) {
            if (!s) { return null; }
            s = s.trim();
            let m;
            m = s.match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/);            if (m) { return [parseFloat(m[1]), parseFloat(m[2])]; }
            m = s.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);                if (m) { return [parseFloat(m[1]), parseFloat(m[2])]; }
            m = s.match(/[?&](?:q|query|ll|center)=(-?\d+\.\d+),(-?\d+\.\d+)/); if (m) { return [parseFloat(m[1]), parseFloat(m[2])]; }
            m = s.match(/^(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)$/);         if (m) { return [parseFloat(m[1]), parseFloat(m[2])]; }
            return null;
        }
        const pegarBtn = document.getElementById('mapa-pegar-btn');
        const pegarInp = document.getElementById('mapa-pegar');
        if (pegarBtn && pegarInp) {
            const usar = function () {
                const c = extraerCoords(pegarInp.value);
                if (c) { map.setView(c, 16); fijar(c[0], c[1]); pegarInp.value = ''; }
                else { alert('No pude leer coordenadas. Pega un enlace de Google Maps con coordenadas (que contenga @lat,lng) o escribe "lat,lng".'); }
            };
            pegarBtn.addEventListener('click', usar);
            pegarInp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); usar(); } });
        }

        // Enlace "abrir en Google Maps"
        const gmap = document.getElementById('abrir-google-maps');
        if (gmap) {
            gmap.addEventListener('click', function (e) {
                e.preventDefault();
                const lat = inLat.value || lat0, lon = inLon.value || lon0;
                window.open('https://www.google.com/maps?q=' + lat + ',' + lon, '_blank');
            });
        }
    }

    /* ---------- Cálculo de retenciones (Solicitud) ---------- */
    function initCalculos() {
        const flete   = document.getElementById('valor_flete');
        const pIca    = document.getElementById('porcentaje_ica');
        const rIca    = document.getElementById('retencion_ica');
        const rFuente = document.getElementById('retencion_fuente');
        const fopat   = document.getElementById('fopat');
        if (!flete || !rFuente) { return; }
        function calc() {
            const f = parseFloat(flete.value) || 0;
            const p = parseFloat(pIca ? pIca.value : 0) || 0;
            if (rIca)   { rIca.value   = (f * p / 1000).toFixed(2); }  // p = tarifa por mil
            rFuente.value = (f * 0.01).toFixed(2);
            if (fopat)  { fopat.value  = (f * 0.001).toFixed(2); }
        }
        flete.addEventListener('input', calc);
        if (pIca) { pIca.addEventListener('input', calc); }
        calc();
    }

    /* ---------- Autocompletar conductor + propietario desde vehículo (despacho) ---------- */
    function initVehiculoConductor() {
        const caja = document.querySelector('[data-ac="vehiculos"].autocompletar');
        if (!caja) { return; }
        caja.addEventListener('ac:select', function (e) {
            const placa = e.detail.placa || '';
            if (!placa) { return; }
            fetch('?r=vehiculo.detalle&placa=' + encodeURIComponent(placa))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.placa) { return; }
                    var cTipo = document.getElementById('conductor_tipo_id');
                    var cNum  = document.getElementById('conductor_num_id');
                    var cLbl  = document.getElementById('conductor_label');
                    if (cTipo) { cTipo.value = d.conductor_tipo_id || ''; }
                    if (cNum)  { cNum.value  = d.conductor_num_id  || ''; }
                    if (cLbl)  { cLbl.textContent = d.conductor_nombre_completo || (d.conductor_tipo_id ? d.conductor_tipo_id + ' ' + d.conductor_num_id : '(no asignado)'); }

                    var tLbl = document.getElementById('tenedor_label');
                    if (tLbl) { tLbl.textContent = d.tenedor_nombre_completo || (d.tenedor_tipo_id ? d.tenedor_tipo_id + ' ' + d.tenedor_num_id : '(no asignado)'); }
                });
        });
    }

    /* ---------- Mostrar municipio al seleccionar remitente/destinatario ---------- */
    function initMuniLabels() {
        document.querySelectorAll('[data-muni-target]').forEach(function (caja) {
            caja.addEventListener('ac:select', function (e) {
                var idSpan = caja.getAttribute('data-muni-target');
                var span = document.getElementById(idSpan);
                if (span) {
                    span.textContent = e.detail.municipio_nombre || (e.detail.cod_municipio || '');
                }
            });
        });
    }

    /* ---------- Mobile Nav Toggle ---------- */
    function initNavToggle() {
        var toggle = document.querySelector('.nav-toggle');
        var nav = document.querySelector('.barra__nav');
        if (!toggle || !nav) return;
        toggle.addEventListener('click', function () {
            nav.classList.toggle('abierto');
        });
        // Close nav when clicking outside
        document.addEventListener('click', function (e) {
            if (nav.classList.contains('abierto') && !nav.contains(e.target) && !toggle.contains(e.target)) {
                nav.classList.remove('abierto');
            }
        });
    }

    /* ---------- Mobile Dropdown Toggle (click instead of hover) ---------- */
    function initMobileDropdowns() {
        if (window.innerWidth > 640) return;
        document.querySelectorAll('.dropdown__toggle').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                var dd = this.closest('.dropdown');
                if (!dd) return;
                dd.classList.toggle('activo');
                // Close other dropdowns
                document.querySelectorAll('.dropdown.activo').forEach(function (other) {
                    if (other !== dd) other.classList.remove('activo');
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initAutocomplete();
        initMapa();
        initCalculos();
        initVehiculoConductor();
        initMuniLabels();
        initNavToggle();
        initMobileDropdowns();
    });
})();
