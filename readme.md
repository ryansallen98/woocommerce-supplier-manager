# WooCommerce Supplier Manager

**Version:** 1.3.2
**Author:** [Ryan Allen](https://github.com/ryansallen98)  
**Requires:** [WooCommerce](https://woocommerce.com/)  

## 📦 Overview

WooCommerce Supplier Manager is a powerful extension for WooCommerce that enables store owners to **manage suppliers directly within WordPress**.  
It provides tools to assign products to suppliers, track supplier costs, handle order fulfilment, and generate supplier-specific documents such as packing slips and invoices.  

This plugin also enhances communication by automatically notifying suppliers of new orders and giving them access to a self-service dashboard in the customer account area.  
Developers can easily extend the system with custom columns, filters, and integrations.

## ✨ Features

### 🔑 Core
- **Supplier Assignment**  
  Assign WooCommerce products to suppliers with cost tracking fields.

- **Supplier Role**  
  A dedicated `supplier` user role with tailored permissions.

- **Supplier Dashboard (My Account)**  
  Suppliers can log in to view:
  - Products assigned to them  
  - Orders awaiting fulfilment  
  - Packing slips and invoices  

- **Order Management**  
  - Supplier fulfilment meta box in admin orders  
  - Supplier-specific order views  
  - Automatic email notifications to suppliers when new orders arrive  

- **Pricing & Cost Tracking**  
  Add and manage supplier costs for each product.  
  View both store totals and supplier cost totals side-by-side.

- **Documents**  
  - Packing slip generation for supplier orders  
  - Invoice support  

- **Email Integration**  
  Suppliers receive automated notifications when they need to act on an order.

### ⚙️ New: Supplier Settings Page
- Accessible under **WooCommerce → Supplier Settings** in the WordPress admin.  
- Allows toggling of **column visibility, sorting, and filtering** in the Supplier Orders table.  
- All columns (including hidden ones) are always shown in the settings screen, so you can toggle them back on at any time.  
- “Filter enabled” toggle lets you turn off a column’s filter/search input without altering its underlying filter type.

### 🧑‍💻 Developer Friendly
- **Column API**  
  Add your own custom columns with the `wcsm_supplier_orders_columns` filter.  
  Columns can define:
  - `label`  
  - `type` (`text`, `number`, `date`, `enum`, `action`)  
  - `sortable` and `filterable` flags  
  - A `value` or custom `render` callback  

- **Help & Documentation in Admin**  
  - A **Help tab** (top-right) and inline **accordion notice** in the settings page explain how to extend columns.  
  - Example code snippets are provided for quick developer onboarding.


## 🚀 Installation

1. Ensure you have WooCommerce installed and active.  
2. Upload the plugin files to `/wp-content/plugins/woocommerce-supplier-manager` or install via the WordPress plugin installer.  
3. Activate the plugin through the **Plugins** menu in WordPress.  
4. Access **WooCommerce → Supplier Settings** to configure your supplier columns.  


## 🔧 Usage

- **Assign Suppliers to Products**  
  Edit any WooCommerce product and set its supplier and cost.  

- **Manage Supplier Orders**  
  Suppliers can log in and view their orders and products under **My Account → Supplier Orders**.  

- **Configure Supplier Order Columns**  
  Store owners can go to **WooCommerce → Supplier Settings** to show/hide columns, enable/disable filters, and make columns sortable.  

- **Generate Documents**  
  Packing slips and invoices are available for supplier fulfilment.  

- **Automatic Notifications**  
  Suppliers receive emails when new orders involving their products are placed.  


## 🛠️ Developer Notes

- Fully OOP structured with autoloading.  
- Hooks and filters available for custom integrations.  
- Template files can be overridden in your theme.  
- Add custom supplier order columns via:

  ```php
  add_filter('wcsm_supplier_orders_columns', function(array $cols) {
      $cols['my-custom'] = [
          'label'      => __('My Custom', 'your-textdomain'),
          'order'      => 30,
          'type'       => 'text',
          'visible'    => true,
          'sortable'   => true,
          'filterable' => 'search',
          'value'      => static function(\WC_Order $order) {
              return get_post_meta($order->get_id(), '_my_custom_meta', true);
          },
      ];
      return $cols;
  });

## 📄 License

This project is licensed under the GPL v2 or later.  