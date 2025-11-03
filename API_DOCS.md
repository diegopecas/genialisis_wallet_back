# CIRCLE FINANCE API - DOCUMENTACIÓN

## 📋 Información General

- **Base URL**: `http://tu-servidor/circle-finance-backend/`
- **Formato**: JSON
- **Autenticación**: JWT Bearer Token
- **Charset**: UTF-8

---

## 🔐 AUTENTICACIÓN

### 1. Login
**Endpoint**: `POST /auth/login`  
**Autenticación**: No requerida  
**Body**:
```json
{
  "email": "diego@lumen.com",
  "password": "123456"
}
```

**Respuesta exitosa (200)**:
```json
{
  "success": true,
  "message": "Login exitoso",
  "data": {
    "user": {
      "id": 1,
      "nombre": "Diego",
      "email": "diego@lumen.com",
      "created_at": "2025-01-01 00:00:00"
    },
    "circulos": [
      {
        "id": 1,
        "nombre": "Familia Lumen",
        "icono": "🏠",
        "color": "#ff9800",
        "descripcion": null,
        "es_admin": true,
        "created_at": "2025-01-01 00:00:00"
      }
    ],
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
  }
}
```

---

### 2. Validar Token (Usuario Actual)
**Endpoint**: `GET /auth/me`  
**Autenticación**: Requerida  
**Headers**: `Authorization: Bearer {token}`

**Respuesta exitosa (200)**:
```json
{
  "success": true,
  "message": "Usuario autenticado",
  "data": {
    "user": { ... },
    "circulos": [ ... ]
  }
}
```

---

## 📂 CONCEPTOS

### 3. Obtener Conceptos Agrupados por Categoría
**Endpoint**: `GET /conceptos?circulo_id={id}&tipo_mov_id={1|2}`  
**Autenticación**: Requerida  
**Query Params**:
- `circulo_id` (required): ID del círculo
- `tipo_mov_id` (required): 1 = Ingreso, 2 = Gasto

**Ejemplo**: `GET /conceptos?circulo_id=1&tipo_mov_id=2`

**Respuesta exitosa (200)**:
```json
{
  "success": true,
  "message": "Conceptos obtenidos correctamente",
  "data": {
    "categorias": [
      {
        "id": 1,
        "nombre": "Alimentación",
        "icono": "🍔",
        "color": "#4caf50",
        "orden": 1,
        "conceptos": [
          {
            "id": 1,
            "nombre": "Mercado",
            "icono": "🛒",
            "es_real": true,
            "requiere_detalle": false,
            "descripcion": "Compras de supermercado"
          },
          {
            "id": 2,
            "nombre": "Restaurante",
            "icono": "🍽️",
            "es_real": true,
            "requiere_detalle": false,
            "descripcion": null
          }
        ]
      }
    ]
  }
}
```

---

### 4. Obtener Todos los Conceptos (Sin Agrupar)
**Endpoint**: `GET /conceptos/all?circulo_id={id}`  
**Autenticación**: Requerida  
**Query Params**:
- `circulo_id` (required): ID del círculo

**Respuesta exitosa (200)**:
```json
{
  "success": true,
  "message": "Conceptos obtenidos correctamente",
  "data": {
    "conceptos": [
      {
        "id": 1,
        "nombre": "Salario",
        "icono": "💼",
        "tipo_mov_id": 1,
        "tipo_movimiento": "Ingreso",
        "es_real": true,
        "requiere_detalle": false,
        "categoria_nombre": "Trabajo",
        "categoria_icono": "💰"
      }
    ]
  }
}
```

---

## 💸 MOVIMIENTOS

### 5. Crear Movimiento
**Endpoint**: `POST /movimientos`  
**Autenticación**: Requerida  
**Body**:
```json
{
  "concepto_id": 1,
  "valor": 50000,
  "fecha": "2025-11-01",
  "circulos_ids": [1],
  "detalle": "Detalle adicional (opcional)",
  "notas": "Notas opcionales"
}
```

**Respuesta exitosa (201)**:
```json
{
  "success": true,
  "message": "Movimiento creado exitosamente",
  "data": {
    "id": 1,
    "user_id": 1,
    "usuario_nombre": "Diego",
    "concepto_id": 1,
    "concepto_nombre": "Mercado",
    "concepto_icono": "🛒",
    "categoria_nombre": "Alimentación",
    "tipo_mov_id": 2,
    "tipo_movimiento": "Gasto",
    "valor": 50000,
    "fecha": "2025-11-01",
    "detalle": null,
    "notas": null,
    "creado_por_ia": false,
    "circulos_nombres": "Familia Lumen",
    "es_compartido": false,
    "created_at": "2025-11-01 10:30:00"
  }
}
```

---

### 6. Obtener Movimientos (Con Filtros)
**Endpoint**: `GET /movimientos`  
**Autenticación**: Requerida  
**Query Params** (todos opcionales):
- `tipo_mov_id`: 1 = Ingreso, 2 = Gasto
- `circulo_id`: ID del círculo
- `anio`: Año (ej: 2025)
- `mes`: Mes (1-12)
- `limit`: Cantidad de resultados (default: 10, max: 100)

**Ejemplos**:
- `GET /movimientos?tipo_mov_id=2&limit=10` (Últimos 10 gastos)
- `GET /movimientos?circulo_id=1&anio=2025&mes=11` (Movimientos de Nov 2025)

**Respuesta exitosa (200)**:
```json
{
  "success": true,
  "message": "Movimientos obtenidos correctamente",
  "data": {
    "movimientos": [ ... ],
    "total": 10
  }
}
```

---

### 7. Obtener Movimiento por ID
**Endpoint**: `GET /movimientos/{id}`  
**Autenticación**: Requerida

**Respuesta exitosa (200)**:
```json
{
  "success": true,
  "message": "Movimiento obtenido correctamente",
  "data": { ... }
}
```

---

### 8. Eliminar Movimiento
**Endpoint**: `DELETE /movimientos/{id}`  
**Autenticación**: Requerida

**Respuesta exitosa (200)**:
```json
{
  "success": true,
  "message": "Movimiento eliminado exitosamente"
}
```

---

### 9. Obtener Balance (Totales)
**Endpoint**: `GET /movimientos/balance`  
**Autenticación**: Requerida  
**Query Params** (todos opcionales):
- `circulo_id`: ID del círculo
- `anio`: Año
- `mes`: Mes (1-12)

**Ejemplo**: `GET /movimientos/balance?circulo_id=1&anio=2025&mes=11`

**Respuesta exitosa (200)**:
```json
{
  "success": true,
  "message": "Balance obtenido correctamente",
  "data": {
    "total_ingresos": 500000,
    "total_gastos": 250000,
    "balance_neto": 250000
  }
}
```

---

### 10. Obtener Balance Detallado por Concepto
**Endpoint**: `GET /movimientos/balance/detalle`  
**Autenticación**: Requerida  
**Query Params** (mismos que balance)

**Respuesta exitosa (200)**:
```json
{
  "success": true,
  "message": "Balance detallado obtenido correctamente",
  "data": {
    "detalle": [
      {
        "concepto_id": 1,
        "concepto_nombre": "Salario",
        "concepto_icono": "💼",
        "categoria_nombre": "Trabajo",
        "tipo_movimiento": "Ingreso",
        "total": 500000,
        "cantidad": 1
      },
      {
        "concepto_id": 2,
        "concepto_nombre": "Mercado",
        "concepto_icono": "🛒",
        "categoria_nombre": "Alimentación",
        "tipo_movimiento": "Gasto",
        "total": 150000,
        "cantidad": 3
      }
    ]
  }
}
```

---

### 11. Obtener Evolución Mensual (Para Gráfico)
**Endpoint**: `GET /movimientos/evolucion`  
**Autenticación**: Requerida  
**Query Params**:
- `circulo_id` (opcional): ID del círculo
- `anio` (opcional, default: año actual): Año

**Ejemplo**: `GET /movimientos/evolucion?circulo_id=1&anio=2025`

**Respuesta exitosa (200)**:
```json
{
  "success": true,
  "message": "Evolución mensual obtenida correctamente",
  "data": {
    "anio": 2025,
    "datos": [
      {
        "mes": 1,
        "mes_nombre": "Ene",
        "ingresos": 0,
        "gastos": 0
      },
      {
        "mes": 2,
        "mes_nombre": "Feb",
        "ingresos": 500000,
        "gastos": 200000
      },
      ...
    ]
  }
}
```

---

## ⚠️ CÓDIGOS DE ERROR

- **200**: OK
- **201**: Created
- **400**: Bad Request (error de validación)
- **401**: Unauthorized (no autenticado o token inválido)
- **404**: Not Found (recurso no encontrado)
- **422**: Unprocessable Entity (errores de validación detallados)
- **500**: Internal Server Error

---

## 🔑 FORMATO DE TOKEN

El token JWT debe enviarse en el header `Authorization`:

```
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

El token expira en **30 días** desde su emisión.

---

## 📝 NOTAS

1. Todos los endpoints (excepto `/auth/login`) requieren autenticación
2. Las fechas deben estar en formato `Y-m-d` (ej: 2025-11-01)
3. Los valores monetarios son de tipo `DECIMAL(15,2)`
4. Los IDs de tipos de movimiento son: **1 = Ingreso**, **2 = Gasto**
5. Si un concepto tiene `requiere_detalle = true`, el campo `detalle` es obligatorio
