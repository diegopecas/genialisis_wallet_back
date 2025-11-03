# 🚀 CIRCLE FINANCE - BACKEND PHP

Backend API REST para Circle Finance - Sistema de gestión financiera con círculos personalizables.

---

## 📋 Requisitos

- PHP 8.0 o superior
- MySQL 8.0 o superior
- Apache con mod_rewrite habilitado
- Extensiones PHP:
  - PDO
  - pdo_mysql
  - json
  - mbstring

---

## 📁 Estructura del Proyecto

```
circle-finance-backend/
├── config/
│   ├── database.php       # Configuración de BD
│   └── jwt.php            # Configuración y funciones JWT
├── models/
│   ├── Database.php       # Conexión PDO (Singleton)
│   ├── Usuario.php        # Modelo de usuarios
│   ├── Concepto.php       # Modelo de conceptos
│   └── Movimiento.php     # Modelo de movimientos
├── controllers/
│   ├── AuthController.php           # Login y autenticación
│   ├── ConceptosController.php      # Gestión de conceptos
│   └── MovimientosController.php    # CRUD de movimientos
├── utils/
│   └── Response.php       # Respuestas JSON estandarizadas
├── .htaccess              # Reescritura de URLs
├── index.php              # Router principal
├── API_DOCS.md            # Documentación de endpoints
└── README.md              # Este archivo
```

---

## 🔧 Instalación

### 1. Clonar/Copiar Archivos

Coloca todos los archivos en tu servidor web (ejemplo: `/var/www/html/circle-finance-backend/`)

### 2. Configurar Base de Datos

La configuración de BD ya está lista en `config/database.php`:

```php
define('DB_HOST', '92.205.2.161');
define('DB_NAME', 'lumen_academico_pdm');
define('DB_USERNAME', 'liceo_lumen_prod');
define('DB_PASSWORD', 'lVuAT1xn2Q-j');
```

**Nota**: La base de datos `lumen_academico_pdm` ya debe estar creada con el esquema de Circle Finance.

### 3. Verificar Permisos

```bash
# Dar permisos de escritura al log de errores (si es necesario)
chmod 664 error.log
```

### 4. Configurar Virtual Host (Opcional)

Si usas Apache, puedes crear un virtual host:

```apache
<VirtualHost *:80>
    ServerName api.circlefinance.local
    DocumentRoot /var/www/html/circle-finance-backend
    
    <Directory /var/www/html/circle-finance-backend>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/circle-finance-error.log
    CustomLog ${APACHE_LOG_DIR}/circle-finance-access.log combined
</VirtualHost>
```

No olvides agregar a `/etc/hosts`:
```
127.0.0.1   api.circlefinance.local
```

### 5. Habilitar mod_rewrite en Apache

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

## 🧪 Probar la API

### Usando cURL

**1. Login:**
```bash
curl -X POST http://tu-servidor/circle-finance-backend/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "diego@lumen.com",
    "password": "123456"
  }'
```

**Respuesta esperada:**
```json
{
  "success": true,
  "message": "Login exitoso",
  "data": {
    "user": { ... },
    "circulos": [ ... ],
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
  }
}
```

**2. Obtener Conceptos (requiere token):**
```bash
curl -X GET "http://tu-servidor/circle-finance-backend/conceptos?circulo_id=1&tipo_mov_id=2" \
  -H "Authorization: Bearer TU_TOKEN_AQUI"
```

**3. Crear Movimiento:**
```bash
curl -X POST http://tu-servidor/circle-finance-backend/movimientos \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TU_TOKEN_AQUI" \
  -d '{
    "concepto_id": 1,
    "valor": 50000,
    "fecha": "2025-11-01",
    "circulos_ids": [1]
  }'
```

---

## 📚 Documentación Completa

Consulta el archivo `API_DOCS.md` para ver todos los endpoints disponibles con ejemplos.

---

## 🔐 Seguridad

### Cambiar Clave JWT en Producción

**Importante**: Antes de llevar a producción, cambia la clave secreta en `config/jwt.php`:

```php
define('JWT_SECRET_KEY', 'TU_CLAVE_SECRETA_SUPER_SEGURA_AQUI');
```

Genera una clave segura con:
```bash
openssl rand -base64 32
```

### HTTPS

En producción, **siempre** usa HTTPS para proteger los tokens JWT.

---

## 🐛 Debugging

### Verificar logs de error

```bash
tail -f error.log
```

### Probar conexión a BD

Puedes crear un archivo `test-db.php` temporal:

```php
<?php
require_once 'config/database.php';
require_once 'models/Database.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    echo "✅ Conexión exitosa a la base de datos\n";
    
    // Probar query simple
    $stmt = $conn->query("SELECT COUNT(*) as total FROM usuarios");
    $result = $stmt->fetch();
    echo "Total usuarios: " . $result['total'] . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
```

---

## 📝 Endpoints Principales

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/auth/login` | Login de usuario |
| GET | `/auth/me` | Validar token |
| GET | `/conceptos` | Obtener conceptos por círculo |
| POST | `/movimientos` | Crear movimiento |
| GET | `/movimientos` | Listar movimientos |
| DELETE | `/movimientos/{id}` | Eliminar movimiento |
| GET | `/movimientos/balance` | Balance total |
| GET | `/movimientos/balance/detalle` | Balance por concepto |
| GET | `/movimientos/evolucion` | Evolución mensual |

---

## 🔄 Flujo de Autenticación

1. Cliente envía `email` y `password` a `/auth/login`
2. Backend valida credenciales y genera JWT
3. Cliente guarda el token (localStorage, sessionStorage, etc.)
4. Cliente incluye token en header `Authorization: Bearer {token}` en todas las peticiones
5. Backend valida el token en cada request protegido

---

## ⚙️ Configuración Avanzada

### Cambiar tiempo de expiración del token

En `config/jwt.php`:

```php
// 30 días (actual)
define('JWT_EXPIRATION_TIME', 30 * 24 * 60 * 60);

// 7 días (ejemplo)
define('JWT_EXPIRATION_TIME', 7 * 24 * 60 * 60);
```

### Limitar origen CORS

En `index.php`, cambiar:

```php
// Actual (permite todos los orígenes)
header('Access-Control-Allow-Origin: *');

// Restringido (ejemplo)
header('Access-Control-Allow-Origin: https://circlefinance.com');
```

---

## 📞 Soporte

Para preguntas o problemas:
- Revisar `API_DOCS.md` para ejemplos de uso
- Verificar logs en `error.log`
- Confirmar que la BD tiene los datos iniciales (usuario, círculo, conceptos)

---

## ✅ Checklist de Implementación

- [x] Configuración de BD
- [x] Sistema de autenticación JWT
- [x] CRUD de movimientos
- [x] Filtros y consultas
- [x] Balance y estadísticas
- [x] Evolución mensual para gráficos
- [x] Validaciones de datos
- [x] Manejo de errores
- [x] CORS habilitado
- [x] Documentación API

---

**¡Backend listo para usar! 🎉**
