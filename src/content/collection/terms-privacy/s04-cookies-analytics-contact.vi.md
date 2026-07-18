---
lang: vi
pageKey: "terms-privacy"
status: "published"
section: "s04"
order: 40
anchor: "cookies-analytics-contact"
navLabel: "Cookie và sự kiện liên hệ"
title: "Cookie, phân tích và tương tác liên hệ"
---

Kssmi tách biệt chức năng sự kiện liên hệ tối thiểu khỏi chức năng phân tích hành trình của khách truy cập dựa trên sự đồng ý. Chúng phục vụ các mục đích khác nhau và không được mô tả như một hệ thống duy nhất có một cơ sở pháp lý duy nhất.

### 1. Sự kiện liên hệ

Khi khách truy cập cố ý chọn liên kết WhatsApp hoặc email, trang web có thể ghi lại một sự kiện tối thiểu cho thấy điểm truy cập liên hệ đã được mở. Nếu không có sự đồng ý về phân tích, sự kiện này được thiết kế để chỉ chứa:

- kênh đã chọn;
- loại sự kiện `open_intent`;
- thời gian máy chủ;
- đường dẫn trang có liên quan trên trang web;
- vị trí liên kết;
- SKU sản phẩm nếu có liên quan;
- ngôn ngữ trang web; và
- trạng thái "ý định" (`intent`).

Nếu không có sự đồng ý phân tích, hồ sơ này không được tạo hoặc đọc mã định danh phiên bản/khách truy cập VJT và không được chứa hành trình duyệt được định tuyến lại, URL liên kết giới thiệu đầy đủ, thông số chiến dịch, địa chỉ IP, tác nhân người dùng hoặc vị trí địa lý. Quá trình xử lý bảo mật ngắn hạn, riêng biệt có thể xảy ra để giới hạn tỷ lệ.

Hồ sơ `open_intent` chỉ có nghĩa là liên kết liên hệ của trang web đã được kích hoạt. Nó không chứng minh rằng một thiết bị đã mở thành công WhatsApp hoặc ứng dụng email khách, rằng khách truy cập đã gửi tin nhắn hay Kssmi đã nhận được một tin nhắn.

Đối với biểu mẫu yêu cầu, sự kiện `submission_success` có nghĩa là quá trình gửi được định cấu hình của trang web đã báo cáo thành công. Nó không chứng minh rằng người nhận đã đọc hoặc trả lời email.

### 2. Theo dõi hành trình của khách truy cập (VJT)

Với sự đồng ý về phân tích, VJT có thể sử dụng mã định danh khách truy cập của bên thứ nhất và mã định danh phiên bản ngắn hạn để liên kết các lượt truy cập trang và sự kiện liên hệ với một hành trình đã được đồng ý. Tùy thuộc vào cấu hình đang hoạt động, dữ liệu hành trình có thể bao gồm:

- URL và tiêu đề trang;
- thời gian truy cập và tương tác;
- thông số liên kết giới thiệu và chiến dịch;
- thông tin về trình duyệt, thiết bị, màn hình, ngôn ngữ và múi giờ;
- quốc gia hoặc thành phố có nguồn gốc từ IP;
- các số đo về thao tác cuộn và mức độ tương tác; và
- phân bổ yêu cầu hoặc sự kiện liên hệ.

Hành trình phân tích phải luôn bị tắt cho đến khi khách truy cập cấp quyền đồng ý phân tích. Nếu rút lại sự đồng ý, việc thu thập số liệu phân tích sau đó phải dừng lại và các mã định danh VJT được lưu trữ trong trình duyệt phải bị xóa theo quy trình rút lại đã được thực hiện.

### 3. Quảng cáo và phân tích của bên thứ ba

Google Analytics, Google Ads, Google Tag Manager hoặc công nghệ đo lường có thể so sánh được phải hoạt động theo các danh mục đồng ý mà khách truy cập đã chọn và cấu hình thực tế của trang web. Thông báo cuối cùng chỉ được mô tả các sản phẩm và tính năng được bật thực sự.

### 4. Cookie và bộ lưu trữ của trình duyệt

Các thời hạn và tiêu chí sau áp dụng cho những hệ thống trang web được mô tả trong thông báo này:

| Tên | Nhà cung cấp | Mục đích | Danh mục | Thời lượng | Loại bộ lưu trữ |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Ghi nhớ các lựa chọn quảng cáo và phân tích của khách truy cập | Cần thiết | Cho đến khi lựa chọn được thay đổi hoặc bộ nhớ trình duyệt bị xóa | Local storage |
| `vjt_visitor_id` | Kssmi | Liên kết các lượt truy cập đã được đồng ý với hành trình của khách truy cập | Phân tích | Cookie: up to about 365 days; local copy: Cho đến khi rút lại sự đồng ý phân tích hoặc xóa bộ nhớ trình duyệt | Cookie và bộ nhớ cục bộ |
| `vjt_session_id` | Kssmi | Liên kết các sự kiện trang đã được đồng ý trong một phiên | Phân tích | About 30 minutes | Cookie |
| Các mã định danh của Google/bên thứ ba khác | Google / relevant third party | Phân tích hoặc quảng cáo | Phân tích/quảng cáo | Thay đổi tùy theo nhà cung cấp và cấu hình | Cookie hoặc công nghệ tương tự |

Hệ thống lưu trữ cookie, biểu ngữ đồng ý và quá trình triển khai trực tiếp phải thống nhất với nhau. Việc đổi tên trình theo dõi hoặc di chuyển mã định danh từ cookie sang bộ nhớ cục bộ không có nghĩa là công nghệ đó được miễn trừ việc cần sự đồng ý.

### 5. Thay đổi các lựa chọn đồng ý

Khách truy cập phải có thể mở lại Cài đặt Cookie và thay đổi hoặc rút lại sự đồng ý về quảng cáo và phân tích một cách dễ dàng như khi họ đã cấp quyền đó. Việc rút lại không ảnh hưởng đến quá trình xử lý hợp pháp trước khi rút lại.
