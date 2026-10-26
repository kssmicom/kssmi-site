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

Đo lường liên hệ và phân tích hành trình đều cần đồng ý phân tích. WhatsApp và email vẫn dùng được khi từ chối theo dõi tùy chọn.

### 1. Sự kiện liên hệ

Chỉ khi đồng ý phân tích, việc chọn liên kết WhatsApp hoặc email mới có thể ghi sự kiện gồm:

- kênh đã chọn;
- loại sự kiện `open_intent`;
- thời gian máy chủ;
- đường dẫn trang có liên quan trên trang web;
- vị trí liên kết;
- SKU sản phẩm nếu có liên quan;
- ngôn ngữ trang web; và
- trạng thái "ý định" (`intent`).

Không đồng ý phân tích thì đo lường liên hệ không xin quyền, đặt cookie đo lường hay ghi thống kê mở. Khi đồng ý, sự kiện có thể gắn hành trình bên dưới. Xử lý bảo mật riêng có thể bảo vệ liên hệ được yêu cầu.

Hồ sơ `open_intent` chỉ có nghĩa là liên kết liên hệ của trang web đã được kích hoạt. Nó không chứng minh rằng một thiết bị đã mở thành công WhatsApp hoặc ứng dụng email khách, rằng khách truy cập đã gửi tin nhắn hay Kssmi đã nhận được một tin nhắn.

Sự kiện `submission_success` nghĩa là website đã nhận yêu cầu vào quy trình gửi, có thể gồm hàng đợi. Không chứng minh email đã giao, một người cụ thể đã nhận, đọc hay trả lời.

### 2. Theo dõi hành trình của khách truy cập (VJT)

Với sự đồng ý về phân tích, VJT có thể sử dụng mã định danh khách truy cập của bên thứ nhất và mã định danh phiên bản ngắn hạn để liên kết các lượt truy cập trang và sự kiện liên hệ với một hành trình đã được đồng ý. Tùy thuộc vào cấu hình đang hoạt động, dữ liệu hành trình có thể bao gồm:

- URL và tiêu đề trang;
- thời gian truy cập và tương tác;
- thông số liên kết giới thiệu và chiến dịch;
- thông tin về trình duyệt, thiết bị, màn hình, ngôn ngữ và múi giờ;
- quốc gia hoặc thành phố có nguồn gốc từ IP;
- các số đo về thao tác cuộn và mức độ tương tác; và
- phân bổ yêu cầu hoặc sự kiện liên hệ.

Hành trình phân tích phải luôn bị tắt cho đến khi khách truy cập đồng ý cho phân tích. Mỗi lần đồng ý bắt đầu một chu kỳ đồng ý mới, và mã định danh VJT không được chuyển từ chu kỳ này sang chu kỳ sau. Khi rút lại sự đồng ý, trang web dừng việc thu thập phân tích mới và cấp mã định danh trong trình duyệt, xóa các giá trị VJT khỏi bộ nhớ trình duyệt và yêu cầu máy chủ cho cookie danh tính HttpOnly hết hạn. Việc xóa này được thử lại một số lần giới hạn và có thể thất bại, chẳng hạn khi trình duyệt ngoại tuyến hoặc yêu cầu bị chặn; cookie danh tính còn sót lại không được dùng để tiếp tục hành trình trước đó và sẽ được thay thế nếu đồng ý lại. Dữ liệu đã gửi trước khi rút lại vẫn có thể được nhận. Các mã định danh được tạo trước khi áp dụng chu kỳ đồng ý sẽ được thay thế một lần. Các trình duyệt không có tính năng cần thiết để phối hợp sự đồng ý giữa các thẻ sẽ không sử dụng VJT.

### 3. Quảng cáo và phân tích của bên thứ ba

Google Analytics, Google Ads, Google Tag Manager hoặc công nghệ tương đương hoạt động theo nhóm đồng ý được chọn và cấu hình đang bật. Lưu trữ phân tích và quảng cáo vẫn bị từ chối đến khi có đồng ý tương ứng.

### 4. Cookie và bộ lưu trữ của trình duyệt

Các mục lưu trữ trình duyệt chính được liệt kê bên dưới. Định danh bên thứ ba có thể thay đổi theo nhà cung cấp, trình duyệt, lựa chọn đồng ý và cấu hình đang bật.

| Tên | Nhà cung cấp | Mục đích | Danh mục | Thời lượng | Loại bộ lưu trữ |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Ghi nhớ các lựa chọn quảng cáo và phân tích của khách truy cập | Cần thiết | Cho đến khi lựa chọn được thay đổi hoặc bộ nhớ trình duyệt bị xóa | Bộ nhớ cục bộ |
| `vjt_visitor_id` | Kssmi | Liên kết các lượt truy cập đã được đồng ý với hành trình của khách truy cập | Phân tích | Cookie: tối đa khoảng 365 ngày; bản sao cục bộ: Cho đến khi rút lại sự đồng ý phân tích hoặc xóa bộ nhớ trình duyệt | Cookie và bộ nhớ cục bộ |
| `vjt_session_id` | Kssmi | Liên kết các sự kiện trang đã được đồng ý trong một phiên | Phân tích | Khoảng 30 phút | Cookie |
| `KSSMI_VJT_IDENTITY` | Kssmi | Danh tính máy chủ có chữ ký, liên kết các lượt truy cập đã đồng ý trong một chu kỳ đồng ý; tập lệnh của trang không đọc được | Phân tích | Tối đa khoảng 365 ngày; hết hạn sau khi rút lại nếu trình duyệt cho phép | Cookie (HttpOnly) |
| `KSSMI_VJT_CONTACT` | Kssmi | Phiên ký số bảo vệ thống kê liên hệ được đồng ý, gắn chu kỳ hiện tại; chỉ cấp khi đồng ý phân tích | Phân tích | Khoảng 30 phút, gia hạn trong khi duyệt | Cookie (HttpOnly) |
| Các mã định danh của Google/bên thứ ba khác | Google / bên thứ ba liên quan | Phân tích hoặc quảng cáo | Phân tích/quảng cáo | Thay đổi tùy theo nhà cung cấp và cấu hình | Cookie hoặc công nghệ tương tự |

Hệ thống lưu trữ cookie, biểu ngữ đồng ý và quá trình triển khai trực tiếp phải thống nhất với nhau. Việc đổi tên trình theo dõi hoặc di chuyển mã định danh từ cookie sang bộ nhớ cục bộ không có nghĩa là công nghệ đó được miễn trừ việc cần sự đồng ý.

### 5. Thay đổi các lựa chọn đồng ý

Khách truy cập phải có thể mở lại Cài đặt Cookie và thay đổi hoặc rút lại sự đồng ý về quảng cáo và phân tích một cách dễ dàng như khi họ đã cấp quyền đó. Việc rút lại không ảnh hưởng đến quá trình xử lý hợp pháp trước khi rút lại. Sau khi đã chọn, nút Cài đặt Cookie vẫn có sẵn trên mọi trang.

### 6. Đếm lượt xem trang ẩn danh
Tách biệt với phân tích dựa trên sự đồng ý được mô tả ở trên, trang web đếm lượt xem trang dưới dạng tổng hợp: với mỗi ngày dương lịch (theo giờ Bắc Kinh) và đường dẫn trang, chỉ lưu tổng số lượt xem. Bảng đếm này không sử dụng cookie, bộ nhớ trình duyệt hoặc mã định danh khách truy cập/phiên và không bao gồm địa chỉ IP, chuỗi tác nhân người dùng, người giới thiệu hoặc dấu thời gian của từng lượt truy cập. Giới hạn tốc độ, lọc bot và nhật ký máy chủ là các hoạt động bảo mật riêng biệt được mô tả trong các mục tương ứng; chúng không thuộc bảng tổng hợp này. Máy chủ có thể đọc riêng một dấu hiệu loại trừ quản trị viên đã ký để tránh đếm lưu lượng quản trị; dấu hiệu đó không được lưu trong bảng tổng hợp ẩn danh.
