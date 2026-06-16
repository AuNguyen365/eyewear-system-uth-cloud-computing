# Eyewear System UTH

Dự án **Eyewear System UTH** được thiết kế và tách biệt hoàn chỉnh thành **2 ứng dụng độc lập** để tối ưu hóa hiệu năng, dễ dàng phát triển và triển khai:

*   **`backend/`**: Hệ thống API viết bằng PHP thuần (N-Layered Architecture), xử lý logic nghiệp vụ và cơ sở dữ liệu MySQL.
*   **`frontend/`**: Giao diện người dùng viết bằng HTML, CSS, và Vanilla JS thuần (kiến trúc Single-page Dashboard Shell & RBAC).
*   **`docker-compose.yml`**: Cấu hình Docker hóa toàn bộ hệ thống bao gồm Frontend (Nginx), Backend (Apache PHP), và Database (MySQL).

---

## 🛠️ Yêu cầu hệ thống (Prerequisites)

Tùy thuộc vào phương pháp bạn chọn để chạy dự án:

1.  **Chạy bằng Docker (Khuyên dùng & Nhanh nhất)**:
    *   Chỉ cần cài đặt [**Docker Desktop**](https://www.docker.com/products/docker-desktop/) (cho Windows/macOS) hoặc Docker Engine (cho Linux).
2.  **Chạy thủ công trên máy (Local Manual)**:
    *   **PHP 8.2 trở lên** và **MySQL Server 8.0/5.7** (Khuyên dùng [**XAMPP**](https://www.apachefriends.org/index.html) hoặc [**Laragon**](https://laragon.org/) đã tích hợp sẵn cả PHP và MySQL).
    *   **Live Server (Extension)**: Dành cho VS Code/Cursor để chạy và tự động tải lại Frontend.

---

## 🚀 Hướng dẫn chạy dự án

> [!TIP]
> **Phương pháp 1 (Docker Compose)** là phương pháp được khuyến nghị tối đa vì hệ thống tự động thiết lập môi trường, cơ sở dữ liệu và dữ liệu mẫu (Mock Data) mà không cần cấu hình thủ công.

### Phương pháp 1: Chạy bằng Docker Compose (Khuyên dùng)

Chỉ cần một dòng lệnh duy nhất để khởi động toàn bộ hệ thống:

1.  Mở terminal tại thư mục gốc của dự án và chạy:
    ```bash
    docker-compose up -d --build
    ```
2.  **Hệ thống sẽ tự động thực hiện các bước sau**:
    *   Tải và dựng các container: `eyewear-mysql`, `eyewear-backend` (Apache), và `eyewear-frontend` (Nginx).
    *   Tự động sao chép tệp cấu hình `.env.example` thành `.env` bên trong container Backend (nếu chưa tồn tại).
    *   Đợi cơ sở dữ liệu MySQL khởi động hoàn tất.
    *   Tự động kiểm tra và import cơ sở dữ liệu (`database/schema.sql`) kèm theo dữ liệu mẫu (`database/seeder.php`).
3.  **Địa chỉ truy cập**:
    *   **Frontend**: [http://localhost:5500](http://localhost:5500)
    *   **Backend API**: [http://localhost:8000](http://localhost:8000) (Trang chủ hiển thị `"Eyewear System UTH Backend API is live"`)
    *   **MySQL Database**: Cổng host `3307` (kết nối nội bộ container dùng port `3306`).
4.  **Lệnh quản lý Docker hữu ích**:
    *   *Dừng hệ thống*: `docker-compose down`
    *   *Xem logs hệ thống*: `docker-compose logs -f`
    *   *Khởi tạo lại cơ sở dữ liệu từ đầu (Reset DB & Seed lại)*:
        ```bash
        docker exec -it eyewear-backend php database/run_schema.php
        ```

---

### Phương pháp 2: Chạy thủ công không dùng Docker (Local Manual)

Nếu bạn không sử dụng Docker, hãy thực hiện lần lượt các bước sau:

#### Bước 1: Thiết lập Cơ sở dữ liệu (MySQL)
1.  Khởi động MySQL Server (từ XAMPP, Laragon hoặc dịch vụ MySQL cài riêng).
2.  Truy cập công cụ quản trị (như phpMyAdmin tại `http://localhost/phpmyadmin` hoặc MySQL Workbench).
3.  Tạo một Database mới tên là: `eyewear_system`.
4.  Mở terminal tại thư mục `backend/`, copy cấu hình môi trường:
    ```powershell
    # Trên Windows (PowerShell)
    copy .env.example .env
    # Trên Linux/macOS
    cp .env.example .env
    ```
5.  Mở file `backend/.env` và chỉnh sửa các thông số kết nối cơ sở dữ liệu (như `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`) cho khớp với máy của bạn.
6.  Chạy script để tự động khởi tạo bảng và chèn dữ liệu mẫu:
    ```bash
    php database/run_schema.php
    ```

#### Bước 2: Chạy Backend (API)
1.  Tại thư mục `backend/`, chạy lệnh khởi động server PHP tích hợp:
    ```bash
    php -S localhost:8000 -t public
    ```
2.  Kiểm tra truy cập tại [http://localhost:8000](http://localhost:8000).

#### Bước 3: Chạy Frontend (UI)
1.  Mở một terminal riêng biệt tại thư mục `frontend/`, chạy server:
    ```bash
    php -S localhost:5500
    ```
    *(Hoặc bạn có thể click chuột phải vào file `frontend/index.html` chọn **Open with Live Server** trong VS Code/Cursor)*.
2.  Kiểm tra truy cập tại [http://localhost:5500](http://localhost:5500).

---

## ⚙️ Hướng dẫn Cấu hình các Dịch vụ Mở rộng

### 1. Cấu hình Email (Cho chức năng khôi phục mật khẩu)
> [!NOTE]
> Hệ thống đã được tối ưu hóa: Khi người dùng đăng ký tài khoản mới, tài khoản sẽ được **kích hoạt trực tiếp ngay lập tức (Active)** để có thể đăng nhập mà không cần qua bước xác minh email. Tuy nhiên, dịch vụ Mail SMTP vẫn cần cấu hình để gửi email mã OTP khi người dùng bấm **Quên mật khẩu**.

*   Mở file `backend/.env`, chỉnh sửa các cấu hình sau:
    ```env
    MAIL_USERNAME=email_cua_ban@gmail.com
    MAIL_PASSWORD=mat_khau_ung_dung_google
    MAIL_FROM_ADDRESS=email_cua_ban@gmail.com
    ```
*   *Cách lấy Mật khẩu ứng dụng (App Password) của Gmail*:
    1.  Vào phần quản lý tài khoản Google cá nhân.
    2.  Đi tới tab **Bảo mật (Security)**.
    3.  Bật **Xác minh 2 bước (2-Step Verification)**.
    4.  Tìm và chọn mục **Mật khẩu ứng dụng (App passwords)**.
    5.  Tạo mật khẩu mới cho ứng dụng "Thư" và copy mã 16 ký tự dán vào `MAIL_PASSWORD` (xóa toàn bộ khoảng trắng).

### 2. Cấu hình Firebase (Cho chức năng Đăng nhập bằng Google)
Hệ thống tích hợp đăng nhập nhanh qua tài khoản Google bằng Firebase Authentication (phương thức OAuth Popup client-side & Backend API verification).

1.  **Phía Frontend**:
    *   Mở file [frontend/js/services/firebaseInit.js](frontend/js/services/firebaseInit.js).
    *   Thay thế các trường thông tin trong đối tượng `firebaseConfig` bằng thông tin cấu hình từ dự án của bạn trên Firebase Console:
        ```javascript
        const firebaseConfig = {
          apiKey: "YOUR_API_KEY",
          authDomain: "YOUR_PROJECT_ID.firebaseapp.com",
          projectId: "YOUR_PROJECT_ID",
          storageBucket: "YOUR_PROJECT_ID.appspot.com",
          messagingSenderId: "...",
          appId: "..."
        };
        ```
2.  **Yêu cầu thiết lập trên Firebase Console**:
    *   Truy cập [Firebase Console](https://console.firebase.google.com/).
    *   Chọn dự án của bạn -> Vào menu **Build** -> **Authentication** -> Chọn tab **Sign-in method**.
    *   Nhấp **Add new provider** -> Chọn **Google** -> Bật nút **Enable**, điền tên dự án và chọn email hỗ trợ -> Nhấn **Save**.

---

## 🏗️ Cấu trúc thư mục dự án

### Backend (`/backend`)
*   **`app/Http/`**: Chứa các Controllers (nhận request, điều phối phản hồi JSON), Middleware, Requests, và Resources.
*   **`app/Application/`**: Chứa các Services (nơi xử lý logic nghiệp vụ chính của từng module như `AuthService`, `ProductService`,...).
*   **`app/Domain/`**: Chứa các thực thể (Entities), interface và quy tắc lõi của miền nghiệp vụ.
*   **`app/Infrastructure/`**: Chứa các thành phần cơ sở hạ tầng (Database connection, Helper load Env, Validator,...).
*   **`app/Models/`**: Các thực thể dữ liệu tương tác trực tiếp với Database thông qua kế thừa `Core\Model`.
*   **`core/`**: Framework Core tự viết (gồm `Router`, `Database`, `Model`, `Session`, `ApiResponse` giúp xử lý request cực nhanh không cần framework nặng).
*   **`database/`**: Chứa file định nghĩa cấu trúc bảng `schema.sql` và file chèn dữ liệu mẫu `seeder.php`.
*   **`routes/`**: Nơi định nghĩa toàn bộ API Endpoint (`api.php`).

### Frontend (`/frontend`)
*   **`pages/`**: Các giao diện chính của hệ thống khách hàng (Shop, Product detail, Cart, Checkout, Auth).
*   **`pages/portal/`**:
    *   `index.html`: Cổng truy cập duy nhất của Dashboard quản trị.
    *   `modules/`: Các trang chức năng động của Dashboard (Overview, Inventory, Orders, Users, Analytics, Profile...) được nạp trực tiếp vào Shell tùy theo phân quyền.
*   **`layouts/`**: Các phần giao diện dùng chung (Header, Footer, Sidebar).
*   **`js/`**:
    *   `core/`: Chứa các logic lõi (`rbac.js` quản lý phân quyền, `layout-loader.js` nạp giao diện động, `layout-guard.js` bảo vệ chặn truy cập chéo).
    *   `services/`: Các module gọi API và giao tiếp với Backend (`authService.js`, `cartService.js`,...).
    *   `pages/`: File JS xử lý tương tác riêng cho từng trang.
    *   `dashboard/`: Logic tương tác và xử lý dữ liệu cho Dashboard của Staff và Admin.

---

## 📊 Xem và Quản lý Cơ sở dữ liệu

Bạn có thể quản trị dữ liệu MySQL theo một trong các cách sau:

1.  **Dành cho Docker**:
    *   Sử dụng bất kỳ MySQL Client nào trên máy của bạn (ví dụ: **DBeaver**, **HeidiSQL**, **MySQL Workbench**, hoặc extension **Database Client** trên VS Code/Cursor).
    *   Cấu hình kết nối:
        *   **Host**: `127.0.0.1`
        *   **Port**: `3307` *(lưu ý: cổng trong docker là 3306 nhưng được map ra ngoài là 3307)*
        *   **Username**: `root`
        *   **Password**: *(để trống)*
        *   **Database**: `eyewear_system`
2.  **Dành cho XAMPP / Laragon (Chạy thủ công)**:
    *   Truy cập `http://localhost/phpmyadmin` trên trình duyệt.
    *   Hoặc kết nối client qua cổng mặc định `3306`.

---

## 🛠️ Quy định Commit (Git Commit Convention)

Để giữ lịch sử git gọn gàng và dễ theo dõi, toàn bộ thành viên hãy tuân thủ cấu trúc sau:

**Cấu trúc**: `<type>(<scope>): <description>`

*   `feat`: Thêm tính năng mới (Ví dụ: `feat(auth): add google login popup`)
*   `fix`: Sửa lỗi (Ví dụ: `fix(auth): redirect staff user to portal correctly`)
*   `docs`: Cập nhật tài liệu, README (Ví dụ: `docs(readme): update docker run guide`)
*   `style`: Format code, chỉnh sửa CSS/UI (Ví dụ: `style(nav): fix mobile responsive layout`)
*   `refactor`: Tối ưu hóa code cũ, không đổi tính năng (Ví dụ: `refactor(db): optimize query fetch inside Model`)
*   `chore`: Cập nhật cấu hình môi trường, gitignore (Ví dụ: `chore(docker): update dockerignore`)

---

## 📄 Liên kết tài liệu tham khảo thêm

*   [Tổng quan phạm vi dự án (Project Scope Summary)](docs/project-scope-summary.md)
*   [Kiến trúc & Cấu trúc chi tiết (Architecture & Structure)](docs/project-structure.md)
*   [Phân chia công việc thành viên (Team Assignments & Process)](docs/team-assignments/)
