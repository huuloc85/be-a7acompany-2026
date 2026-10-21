# Quy tắc làm việc trong dự án

## Từ ngữ trong thông báo và giao diện

- Dùng đúng thuật ngữ nghiệp vụ, tên chức năng và cách gọi đang có trong hệ thống. Trước khi thêm hoặc sửa nội dung hiển thị, kiểm tra các luồng liên quan để giữ cách dùng từ nhất quán với frontend.
- Viết tiếng Việt ngắn gọn, tự nhiên, rõ việc; không dùng văn phong “AI hoá”, lời dẫn dài dòng, câu quảng cáo, sáo rỗng hoặc giọng trợ lý trò chuyện.
- Áp dụng cho nội dung gửi đến người dùng: thông báo API, lỗi validation, notification, email và nội dung giao diện do backend render. Không tự đổi mã lỗi, key JSON hoặc tên trường API chỉ để sửa câu chữ.
- Thông báo cần nói rõ tình trạng và thao tác cần làm khi có; dùng đúng tên người phụ trách theo nghiệp vụ, không tự thay bằng cách gọi chung chung. Ví dụ trong luồng chấm công: “Vui lòng báo tổ trưởng để được xác nhận giờ vào/ra.”
- Không tự đặt thuật ngữ mới hoặc đổi cách gọi đã thống nhất. Nếu chưa rõ thuật ngữ nghiệp vụ, hỏi lại người dùng.

## Kiểm tra sau khi sửa code PHP/Laravel

- Sau khi hoàn tất thay đổi code PHP, bắt buộc chạy `php -l <đường-dẫn-file>` cho từng file PHP đã thêm hoặc sửa và `vendor/bin/pint --test <các-đường-dẫn-file-PHP-đã-thay-đổi>` để kiểm tra định dạng trong phạm vi tác vụ.
- Chạy PHP lint, Pint và các test liên quan riêng biệt, không nối bằng `&&`, để một bước thất bại không làm bỏ qua các bước kiểm tra còn lại. Dùng `php artisan test --filter=<tên-test>` hoặc chỉ định đường dẫn test phù hợp; chỉ báo đạt đối với các kiểm tra thực sự đã chạy thành công.
- Trước khi chạy test có truy cập hoặc ghi database, xác nhận kết nối đang trỏ đến database test riêng. `APP_ENV=testing` không đủ để bảo đảm an toàn; không chạy test có migration, seeder hoặc `RefreshDatabase` trên database dùng thật. Nếu chưa xác nhận được môi trường test, dừng bước đó và báo rõ.
- Không tự chạy migration, seeder, lệnh xóa dữ liệu, `composer update` hoặc thay đổi môi trường triển khai ngoài phạm vi được người dùng yêu cầu.
- Không áp dụng ESLint/TypeScript cho thay đổi chỉ liên quan PHP. Không chạy `npm run build` hoặc `vite build` trừ khi người dùng yêu cầu.
- Sửa lỗi do thay đổi của mình gây ra rồi chạy lại kiểm tra. Nếu gặp lỗi có sẵn ngoài phạm vi tác vụ, báo rõ thay vì sửa lan sang phần khác. Không chạy formatter tự sửa trên toàn bộ dự án.
- Trong kết quả bàn giao, nêu trạng thái PHP lint, Pint và các test đã chạy; báo rõ bước chưa chạy, bị chặn hoặc thất bại. Nếu chỉ sửa tài liệu, không cần chạy kiểm tra code, nhưng phải nói rõ.

## Test tạm và dọn dẹp sau kiểm tra

- Có thể tạo file test tạm để tái hiện lỗi và xác minh thay đổi; ưu tiên thư mục tạm của hệ thống, không đặt trong `app/`, `routes/` hoặc `public/`.
- Nếu test cần nằm trong `tests/` để chạy đúng Laravel/PHPUnit, chỉ tạo file tạm có tên rõ ràng và xóa sau khi kiểm tra, trừ khi người dùng yêu cầu giữ test lâu dài.
- Sau khi kiểm tra hoàn tất, xóa các file test tạm, fixture và đầu ra kiểm tra tạm do mình tạo cho tác vụ. Không để chúng trong diff hoặc commit.
- Không xóa file chỉ vì có đuôi `.php`, tên chứa `test` hoặc nằm trong `tests/`; chỉ dọn những file xác định là test tạm do mình tạo.
- Giữ nguyên test có sẵn và file của người dùng. Chỉ thêm hoặc giữ test lâu dài khi người dùng yêu cầu.
- Trước khi bàn giao, kiểm tra `git status --short` để chắc chắn không còn file test tạm của tác vụ; báo rõ kết quả kiểm tra và phần chưa kiểm tra được.

<!-- gitnexus:start -->

# GitNexus — Code Intelligence

This project is indexed by GitNexus as **be-a7acompany-2026** (9113 symbols, 19278 relationships, 246 execution flows).

> Index stale? Run `node .gitnexus/run.cjs analyze --index-only` from the project root — it auto-selects an available runner. No `.gitnexus/run.cjs` yet? Bootstrap with `npx`, `bunx`, or `pnpm dlx` — e.g. `bunx gitnexus@latest analyze` (npm 11 npx crash; #1939).

## Always Do

- **MUST run impact before editing.** Use `impact({target: "symbolName", direction: "upstream"})` or `node .gitnexus/run.cjs impact "symbolName" --direction upstream --repo .`; report callers, processes, and risk. Never substitute grep for graph analysis.
- **MUST analyze graph changes before committing.** Use `detect_changes({scope: "all"})` (MCP) or `node .gitnexus/run.cjs detect-changes --scope all --repo .` (CLI fallback). `partial: true` or `truncated: true` is not a clean check — a zero means unseen, not unaffected; re-run it. For regression review: `detect_changes({scope: "compare", base_ref: "release-project"})` or `node .gitnexus/run.cjs detect-changes --scope compare --base-ref "release-project" --repo .`.
- MUST warn on HIGH/CRITICAL `risk` pre-edit; never use `riskSharedAxes` to waive a HIGH/CRITICAL `risk` warning. Compare File/symbol: MCP File omits axes; Graph-RAG expands File.
- **MUST treat `risk: UNKNOWN` as unresolved, not as low.** An empty caller set is not evidence the symbol is unused — it can also mean the callers are not resolvable by the index (plain-object property access, dynamic dispatch, cross-language calls). `impact` pairs `UNKNOWN` with a `riskNote` saying so. Confirm with a text search before treating the symbol as safe to change or delete; do not proceed on the strength of a zero.
- **MUST use `query({search_query: "concept"})` for concepts/flows, `context({name: "symbolName"})` for a named symbol, or `impact` for blast radius, on read-only callers, dependencies, imports, or execution flow.** Graph first; text search only for empty/`UNKNOWN`/literals.
- For security review, `explain({target: "fileOrSymbol"})` lists taint findings (source→sink flows; needs `analyze --pdg`).

## Never Do

- NEVER edit a function, class, or method before MCP/CLI impact analysis.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis, and never read `UNKNOWN` as an all-clear — it means the walk could not answer, which is the one verdict that requires confirming by other means.
- NEVER rename symbols with find-and-replace — use `rename` which understands the call graph.
- NEVER commit before MCP/CLI graph change analysis.

## Resources

| Resource                                            | Use for                                  |
| --------------------------------------------------- | ---------------------------------------- |
| `gitnexus://repo/be-a7acompany-2026/context`        | Codebase overview, check index freshness |
| `gitnexus://repo/be-a7acompany-2026/clusters`       | All functional areas                     |
| `gitnexus://repo/be-a7acompany-2026/processes`      | All execution flows                      |
| `gitnexus://repo/be-a7acompany-2026/process/{name}` | Step-by-step execution trace             |

## CLI

| Task                                         | Read this skill file                               |
| -------------------------------------------- | -------------------------------------------------- |
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus-exploring/SKILL.md`       |
| Blast radius / "What breaks if I change X?"  | `.claude/skills/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?"             | `.claude/skills/gitnexus-debugging/SKILL.md`       |
| Rename / extract / split / refactor          | `.claude/skills/gitnexus-refactoring/SKILL.md`     |
| Tools, resources, schema reference           | `.claude/skills/gitnexus-guide/SKILL.md`           |
| Index, status, clean, wiki CLI commands      | `.claude/skills/gitnexus-cli/SKILL.md`             |

<!-- gitnexus:end -->
