# ⚽ Cedeka World Cup — Sistema de Quiniela

Sistema completo de apuestas de minuto exacto de gol, con billetera en Cedenas.

---

## 📁 Estructura del proyecto

```
cedeka/
├── index.php              ← Router principal (usuarios)
├── admin/
│   └── index.php          ← Panel de administración
├── includes/
│   ├── config.php         ← BD, sesión, helpers, lógica del pozo
│   └── layout.php         ← Plantillas HTML reutilizables
├── assets/
│   ├── css/main.css       ← Estilos completos
│   └── js/app.js          ← JS del frontend
├── database.sql           ← Esquema + datos iniciales
└── .htaccess              ← Seguridad y reescritura de URLs
```

---

## 🚀 Instalación

### 1. Requisitos
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Apache con `mod_rewrite` habilitado

### 2. Base de datos
```sql
mysql -u root -p < database.sql
```
O importa `database.sql` desde phpMyAdmin.

### 3. Configura la conexión
Edita `includes/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'cedeka_quiniela');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');
```

### 4. Servidor web
```bash
# Desarrollo rápido con PHP
cd cedeka/
php -S localhost:8000

# O configura Apache con DocumentRoot apuntando a /cedeka
```

### 5. Admin por defecto
- **URL**: `/admin/index.php`
- **Email**: `admin@cedeka.com`
- **Password**: `admin123` ← ¡Cámbialo en producción!

---

## 🔄 Flujo completo del sistema

### Usuario:
1. Se registra → recibe billetera en 0 Cedenas
2. Solicita recarga → admin aprueba y acredita Cedenas
3. Elige partido abierto → elige equipo → elige minuto (1-90) → monto
4. Ve sus apuestas y resultados en "Mis Apuestas"

### Admin:
1. Crea partidos con equipos, flags y fecha
2. Cambia estado del partido: `open` → `in_progress` → `closed`
3. Registra goles (equipo + minuto exacto)
4. Presiona "Calcular Ganadores" → el sistema:
   - Encuentra quién apostó ese equipo EN ESE MINUTO EXACTO
   - Calcula 10% de comisión para el sitio
   - Reparte el 90% restante entre ganadores
   - Si nadie acertó → el pozo se acumula (no se distribuye)

---

## 💰 Lógica del pozo

```
Pozo Total = Σ apuestas del partido
Comisión   = Pozo × 10%
A Repartir = Pozo × 90%
Premio      = A Repartir ÷ Cantidad de ganadores

Si nadie acierta → el estado queda 'closed' (no 'finished')
                    y el pozo permanece para el próximo partido
```

**Ganador**: quien apostó el equipo correcto en el minuto EXACTO del gol.

Si hay 3 goles en el partido, una apuesta gana si coincide con CUALQUIERA de ellos.

---

## 🔒 Seguridad incluida

- Contraseñas con `password_hash()` (bcrypt)
- Prepared statements en todas las consultas (PDO)
- `htmlspecialchars()` en todo output
- Validación del equipo contra los equipos del partido
- Restricción de acceso admin por rol en sesión
- Restricción de directorio `/includes/` via `.htaccess`
- UNIQUE KEY en bets para evitar apuestas duplicadas

---

## 🛠 Personalización

### Cambiar comisión (default 10%)
```php
// includes/config.php
define('SITE_COMMISSION', 0.10); // 0.05 = 5%, 0.15 = 15%
```

### Cambiar mínimo de apuesta
```php
define('MIN_BET', 1);   // mínimo en Cedenas
define('MAX_BET', 10000);
```

### Agregar método de pago
En `index.php`, función `pageRecharge()`, agrega en el `<select>`:
```html
<option value="paypal">PayPal</option>
```

---

## 📱 Pantallas incluidas

| Pantalla | URL |
|---|---|
| Inicio | `/?page=home` |
| Login | `/?page=login` |
| Registro | `/?page=register` |
| Partidos | `/?page=matches` |
| Hacer apuesta | `/?page=bet&id={ID}` |
| Mis apuestas | `/?page=my_bets` |
| Wallet | `/?page=wallet` |
| Recargar | `/?page=recharge` |
| Admin dashboard | `/admin/` |
| Admin partidos | `/admin/?page=matches` |
| Admin goles | `/admin/?page=goals` |
| Admin recargas | `/admin/?page=recharges` |
| Admin usuarios | `/admin/?page=users` |
