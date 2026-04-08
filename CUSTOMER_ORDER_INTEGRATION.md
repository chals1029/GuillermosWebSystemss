# Customer Order System Integration Guide

## Overview
The CustomerController now has complete order management functionality:
- Place orders with automatic stock deduction
- View order history
- Cancel pending orders (only)
- Generate printable receipts

## Database Schema Used
- `orders` - Main order records (Status: Pending/Reserved/Completed)
- `order_detail` - Individual items in each order
- `payment` - Payment records with change calculation
- `invoice` - Invoice records generated for each order
- `product` - Stock automatically deducted on order placement

## Important Rules
1. **Order Cancellation**: Only orders with Status='Pending' can be cancelled
2. **Stock Management**: Stock is deducted when order is placed, restored if cancelled
3. **Invoice Status**: Automatically set to 'Pending' on order placement
4. **Receipt**: Can be generated immediately after order placement

---

## JavaScript Integration Examples

### 1. Place Order (New Checkout System)

```javascript
async function placeOrder() {
    const userId = <?= $_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? 0 ?>;
    if (!userId) {
        alert('Please log in to place an order');
        return;
    }

    // Prepare cart items with product IDs
    const cartItems = [];
    let totalAmount = 0;

    for (const [productName, item] of Object.entries(cart)) {
        // You need to get Product_ID - either store it when adding to cart or fetch it
        const productId = await getProductIdByName(productName);
        
        cartItems.push({
            product_id: productId,
            product_name: productName,
            quantity: item.quantity,
            price: item.price
        });
        
        totalAmount += item.price * item.quantity;
    }

    const orderData = {
        items: cartItems,
        total_amount: totalAmount,
        payment_method: document.querySelector('input[name="payment_method"]:checked')?.value || 'Cash',
        amount_tendered: parseFloat(document.getElementById('amount_tendered')?.value || totalAmount),
    };

    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'place_order',
                order_data: JSON.stringify(orderData)
            })
        });

        const result = await response.json();
        
        if (result.status === 'success') {
            alert(`Order placed successfully! Order ID: ${result.order_id}`);
            
            // Show receipt option
            if (confirm('Would you like to view your receipt?')) {
                window.open(`?action=generate_receipt&order_id=${result.order_id}`, '_blank');
            }
            
            // Clear cart and reload
            location.reload();
        } else {
            alert(result.message || 'Failed to place order');
        }
    } catch (error) {
        console.error('Order error:', error);
        alert('An error occurred while placing your order');
    }
}

// Helper function to get Product_ID
async function getProductIdByName(productName) {
    // This should be cached when products are loaded
    // For now, you can add Product_ID to the cart when adding items
    return productIdCache[productName]; // Implement this cache
}
```

### 2. View Order History

```javascript
async function loadOrderHistory() {
    try {
        const response = await fetch('?action=get_orders', {
            method: 'GET',
        });

        const result = await response.json();
        
        if (result.status === 'success') {
            displayOrders(result.orders);
        } else {
            console.error('Failed to load orders');
        }
    } catch (error) {
        console.error('Error loading orders:', error);
    }
}

function displayOrders(orders) {
    const container = document.getElementById('orders-container');
    container.innerHTML = '';

    if (orders.length === 0) {
        container.innerHTML = '<p>No orders yet</p>';
        return;
    }

    orders.forEach(order => {
        const orderCard = document.createElement('div');
        orderCard.className = 'order-card';
        orderCard.innerHTML = `
            <div class="order-header">
                <h4>Order #${order.OrderID}</h4>
                <span class="status-badge status-${order.Status.toLowerCase()}">${order.Status}</span>
            </div>
            <div class="order-details">
                <p>Date: ${new Date(order.Order_Date).toLocaleDateString()}</p>
                <p>Total: ₱${parseFloat(order.Total_Amount).toFixed(2)}</p>
                <p>Payment: ${order.Mode_Payment}</p>
            </div>
            <div class="order-items">
                ${order.items.map(item => `
                    <div class="order-item">
                        ${item.Product_Name} x${item.Quantity} - ₱${parseFloat(item.Subtotal).toFixed(2)}
                    </div>
                `).join('')}
            </div>
            <div class="order-actions">
                <button onclick="viewReceipt(${order.OrderID})" class="btn-secondary">View Receipt</button>
                ${order.Status === 'Pending' ? 
                    `<button onclick="cancelOrder(${order.OrderID})" class="btn-danger">Cancel Order</button>` : 
                    ''}
            </div>
        `;
        container.appendChild(orderCard);
    });
}
```

### 3. Cancel Order (Pending Only)

```javascript
async function cancelOrder(orderId) {
    if (!confirm('Are you sure you want to cancel this order?')) {
        return;
    }

    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'cancel_order',
                order_id: orderId
            })
        });

        const result = await response.json();
        
        if (result.status === 'success') {
            alert('Order cancelled successfully');
            loadOrderHistory(); // Reload orders
        } else {
            alert(result.message || 'Cannot cancel this order');
        }
    } catch (error) {
        console.error('Cancel error:', error);
        alert('An error occurred while cancelling the order');
    }
}
```

### 4. View Receipt

```javascript
function viewReceipt(orderId) {
    // Opens receipt in new window/tab
    window.open(`?action=generate_receipt&order_id=${orderId}`, '_blank');
}
```

### 5. Enhanced Cart - Store Product IDs

```javascript
// IMPORTANT: Modify your add to cart to include Product_ID
async function addToCart(productName) {
    const productData = productsCache.find(p => p.Product_Name === productName);
    
    if (!productData) {
        console.error('Product not found');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'increase');
    formData.append('product', productName);

    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });

        const newCount = await response.text();
        updateCartCount(newCount);

        // Also store product ID in a client-side cache
        if (!window.productIdCache) {
            window.productIdCache = {};
        }
        window.productIdCache[productName] = productData.Product_ID;

    } catch (error) {
        console.error('Cart error:', error);
    }
}
```

---

## CSS Styles for Order Display

```css
.order-card {
    background: #fff;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 15px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid #f0f0f0;
    padding-bottom: 10px;
    margin-bottom: 15px;
}

.status-badge {
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 600;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-completed {
    background: #d4edda;
    color: #155724;
}

.status-cancelled {
    background: #f8d7da;
    color: #721c24;
}

.order-items {
    margin: 15px 0;
    padding: 10px;
    background: #f9f9f9;
    border-radius: 5px;
}

.order-item {
    padding: 5px 0;
    border-bottom: 1px dashed #ddd;
}

.order-item:last-child {
    border-bottom: none;
}

.order-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

.btn-secondary {
    background: #6c757d;
    color: #fff;
    border: none;
    padding: 8px 15px;
    border-radius: 5px;
    cursor: pointer;
}

.btn-danger {
    background: #dc3545;
    color: #fff;
    border: none;
    padding: 8px 15px;
    border-radius: 5px;
    cursor: pointer;
}
```

---

## Complete Checkout Flow

1. **Customer adds items to cart** (existing functionality)
2. **Customer proceeds to checkout**
3. **Select payment method** (Cash/GCash)
4. **Enter amount tendered** (for Cash)
5. **Call `placeOrder()`** → Creates records in:
   - `orders` (Status: Pending)
   - `order_detail` (all items)
   - `payment` (with change calculation)
   - `invoice` (Invoice_Status: Pending)
   - Updates `product` stock
6. **Show confirmation** with option to view receipt
7. **Staff can mark as Completed** (implement in Staff dashboard)
8. **Customer can only cancel if Status='Pending'**

---

## API Endpoints Summary

| Action | Method | Parameters | Returns |
|--------|--------|------------|---------|
| Place Order | POST | action=place_order, order_data (JSON) | {status, order_id, invoice_id, total, change} |
| Get Orders | GET | action=get_orders | {status, orders: [...]} |
| Cancel Order | POST | action=cancel_order, order_id | {status, message} |
| Get Order Details | GET | action=get_order_details, order_id | {status, order: {...}} |
| Generate Receipt | GET | action=generate_receipt, order_id | HTML receipt page |

---

## Testing Checklist

- [ ] Can add products to cart
- [ ] Can place order with Cash payment
- [ ] Can place order with GCash payment  
- [ ] Order appears in order history
- [ ] Receipt displays correctly
- [ ] Can cancel Pending order
- [ ] Cannot cancel Completed order
- [ ] Stock is deducted correctly
- [ ] Stock is restored on cancellation
- [ ] Change is calculated correctly for Cash
- [ ] Invoice record is created

---

## Next Steps for Complete Integration

1. **Modify your cart display** to include Product_ID when products load
2. **Add Order History page/section** in Customer.php
3. **Implement payment modal** with Cash/GCash selection
4. **Add receipt button** to order history items
5. **Staff Dashboard**: Add ability to mark orders as Completed
6. **Notifications**: Show pending order count to staff

Need help with any specific part? Let me know!
