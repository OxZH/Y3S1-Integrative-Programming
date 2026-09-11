---
name: Stripe Connect Payment Flow
overview: 建立完整的 Stripe Test Mode 双边付款模块：Organizer 支付场地费并结算给 Facility Owner；Participant 支付参加费，活动完成后结算给 Organizer；活动开始前或 Event 取消时全额退款。模块会读取 Jianyu 的 Event/Facility API，并提供付款状态、取消退款及活动结算 API 给 Jianyu。
todos:
  - id: payment-core
    content: 用最少的数据表和 PHP 类实现 venue payment、participant payment、refund 与 payout
    status: pending
  - id: stripe-connect
    content: 接入 Stripe Test Mode、Connect onboarding、Webhook、transfer 与 reversal
    status: pending
  - id: payment-pages
    content: 用一个 Payment controller 和少量 views 完成 Organizer、Participant 与 Connect 页面
    status: pending
  - id: jianyu-api
    content: 读取 Jianyu Event/Facility API，并提供精简的 payment IFA API
    status: pending
  - id: verify-only
    content: 开发时验证主要流程，不创建或保留自动化测试文件
    status: pending
isProject: false
---

# Stripe Connect 双边付款流程

## 资金与状态设计
- 采用 Stripe **separate charges and transfers**：平台账户通过 PaymentIntent 收款，Webhook 是唯一付款成功依据，再通过 Connect Transfer 结算。
- Venue flow：Organizer 创建 Event → 模块调用 Jianyu 的 `getEventDetails`、`getFacilityDetails` 验证 host、场地、时间与价格 → Organizer 支付场地费 → Webhook 标记 `PAID` → 转账给 Facility Owner → Jianyu 通过 `getBookingStatus` 后才允许发布。
- Participant flow：Participant 报名 → 支付 `feePerParticipant` → Webhook 确认后报名 `CONFIRMED` → 活动完成后，把未退款的参加费合并转给 Organizer。
- Refund：Participant 在活动开始前取消则全额退款；Event 取消则退还全部 participant fee 和 venue fee。若 venue 款已转给 Facility Owner，先执行 transfer reversal，再退款。

## 数据库与领域层
- 尽量复用现有 `Booking`、`Payment`、`Refund`，只在 [database/schema.sql](database/schema.sql) 增加必要的 `ParticipantPayment`、`ConnectAccount`、`Transfer` 和 `StripeWebhookEvent`；不拆成大量细表。
- 不为每张付款表建立独立 Entity 和 Mapper。只增加一个 `PaymentService` 集中处理 PDO 查询、状态变化、transaction、退款和 payout，降低文件数量。
- 所有金额由服务器根据 Event/Facility API 计算并保存快照；Stripe ID、Webhook event ID 和 IFA `requestId` 用于幂等，防止重复扣款、退款或转账。

## Stripe Test Mode
- 通过 Composer 引入唯一的第三方依赖 `stripe/stripe-php`，在 [config.php](config.php) 读取 secret key、publishable key、webhook secret 和 Connect URL；密钥不写入仓库。
- 只增加一个 `StripeService` 封装 Connect Express onboarding、PaymentIntent、Refund、Transfer 和 Transfer Reversal，不再拆分多个 gateway/service。
- 增加独立 webhook endpoint，验证 `Stripe-Signature`，处理 `payment_intent.succeeded`、`payment_intent.payment_failed`、`charge.refunded`、`account.updated`、transfer/reversal 结果，并用 event ID 防止重复处理。

## 页面流程
- 只增加一个 `PaymentController`，处理 venue checkout、participant checkout、取消退款、Connect onboarding 和付款状态；避免为每个流程建立 Controller。
- 用少量共用 views 显示 Stripe Payment Element、付款结果、Connect 状态及 Organizer 收款汇总；不制作独立后台、报表或复杂付款历史页面。
- 保留 [public/booking.php](public/booking.php) 作为 Jianyu 创建 Event 后的入口，但移除目前直接写入 `PAID` 的模拟逻辑。

## 与 Jianyu 的 API 集成
- 直接复用 [app/Service/ServiceClient.php](app/Service/ServiceClient.php) 和 [app/Service/Ifa.php](app/Service/Ifa.php)，在现有 [app/Service/RemoteServices.php](app/Service/RemoteServices.php) 增加 `getEventDetails` 与 `getFacilityDetails`，不新增另一个 API client 类。
- 新增 `public/api/payment.php`，沿用现有 IFA `S/F/E` envelope，提供：
  - `getBookingStatus(eventId)`：兼容 Jianyu 当前的 `BookingStatus` DTO。
  - `getEventPaymentSummary(eventId)`：venue payment、participant collection、refund、payout 汇总。
  - `cancelEventPayments(eventId, reason)`：Event 取消时幂等全额退款并反转必要 transfer。
  - `settleEventPayout(eventId)`：Event 完成后幂等转账给 Organizer。
- 两个 mutation API 使用一个共享 service key 和 `requestId` 幂等检查；不增加复杂的 OAuth 或额外认证服务。
- 更新 [app/Service/RemoteServices.php](app/Service/RemoteServices.php) 与 [config.php](config.php)，让 Jianyu 的模块改用真实 `payment.php`；将 [app/Controller/EventController.php](app/Controller/EventController.php) 的 hand-off URL 指向真实 booking checkout。Event 取消和完成时分别调用退款与结算 API。

## 验证
- 不创建 PHPUnit、tests 目录、fixture 或任何需要交付的自动化测试文件。
- 开发过程中临时运行 PHP syntax/lint，并用 Stripe CLI Test Mode 验证：Organizer venue payment → Facility Owner transfer → Event publish；Participant payment → 开始前取消退款，或 Event complete → Organizer payout。
- 完成前验证重复 Webhook、重复 API 请求、Event 取消全额退款及异常信息；只保留业务代码和必要说明。

## 文件数量控制
- 主要新增文件控制在约 6 个：`PaymentService`、`StripeService`、`PaymentController`、共用 payment view、`api/payment.php`、`api/stripe-webhook.php`。
- 其余只修改现有 `schema.sql`、`config.php`、`RemoteServices.php`、front controller、`booking.php` 和必要 CSS/README；不增加 Repository、DTO、Factory、多个小 Service 或测试文件。