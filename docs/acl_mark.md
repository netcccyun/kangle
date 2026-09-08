# ACL 与 Mark 配置参考

ACL 是条件匹配模块，Mark 是命中规则时执行的处理模块。它们只能出现在 `<request>` 或 `<response>` 的 `<table>/<chain>` 中。模块名和可用阶段来自当前源码的 `KAccess::loadModel()` 注册表。

> 模块可能受操作系统或编译选项限制。管理界面的“可用 ACL/Mark”列表是当前运行二进制的最终准确信息。

## 执行模型

```xml
<request action='vhs'>
    <table name='BEGIN'>
        <chain action='deny'>
            <acl module='path' path='/private/*'/>
            <acl module='srcs' v='10.0.0.0/8|192.168.0.0/16' revers='1'/>
        </chain>
        <chain action='continue'>
            <acl module='path' path='/api/*'/>
            <mark module='add_header' attr='X-Route' val='api'/>
        </chain>
    </table>
</request>
```

- 表按配置文件加载顺序和链顺序依次匹配。
- 一个链中的 ACL 默认是逻辑与；ACL 全部成功后才执行 Mark。
- `or='1'` 表示当前模块与下一个模块组成逻辑或关系。它不是“与上一个模块 OR”，因此应写在 OR 组中除最后一个以外的项目上。
- `revers='1'` 对当前 ACL 或 Mark 的布尔结果取反。属性名是源码保留的历史拼写，不能写成 `reverse`。
- Mark 按声明顺序执行。Mark 失败也会使链不匹配；需要组合 Mark 返回值时同样可以使用 `or`/`revers`，但通常不建议依赖这种高级行为。
- 链命中后执行 `chain@action`。`continue` 会继续匹配当前表的后续链。
- 表递归跳转最多 32 层；超过会拒绝请求。

### 表和动作

- `BEGIN`：请求或响应阶段的入口表。
- `POSTMAP`：仅响应规则使用，在 URL 已映射为物理文件后执行，适合文件类 ACL。
- 常用动作：`allow`、`deny`、`drop`、`continue`、`return`（别名 `default`）、`table:名称`、`server:名称`、`wback:名称`、`proxy`。
- 根级请求规则还可使用 `vhs`、`api:名称`、`cmd:名称`、`dso:扩展:处理器`。这些全局跳转在 `<vh>` 内的局部规则中不可用。
- `tablechain:表:序号` 是管理界面使用的内部跳链格式，不建议手写。

`request@action` 和 `response@action` 是入口表没有产生终止动作时的默认动作。

## 匿名模块和命名模块

匿名模块用 `module` 指定实现：

```xml
<acl module='host' v='example.com|www.example.com'/>
<mark module='speed_limit' limit='1M'/>
```

可复用配置可定义为命名模块，再通过 `ref` 引用：

```xml
<request action='vhs'>
    <named_acl name='local_net' module='srcs' v='10.0.0.0/8|192.168.0.0/16'/>
    <named_mark name='api_limit' module='speed_limit' limit='2M'/>
    <table name='BEGIN'>
        <chain action='continue'>
            <acl ref='local_net'/>
            <mark ref='api_limit'/>
        </chain>
    </table>
</request>
```

命名 ACL 与命名 Mark 的 `name` 在各自规则作用域内必须唯一。引用节点只应写 `ref`、`or`、`revers`；模块参数应放在定义处，避免意外修改共享实例。

## 通用参数约定

- `v`：值或值列表。
- `split`：列表分隔符，只取第一个字符，默认通常是 `|`。
- `icase='1'`：忽略大小写。部分模块固定大小写规则，不接受此开关。
- `nc='1'`：正则忽略大小写。
- `raw='1'`：使用进入服务器时的原始 URL，而不是重写后的 URL。
- 正则模块使用项目编译时选择的 PCRE/PCRE2 实现。XML 属性中的 `&`、`<` 等字符仍需 XML 转义。

## 内置 ACL

“请求/响应”表示两个阶段均注册；“条件”表示只有相应编译功能启用时才存在。

### URL、主机和请求属性

| 模块 | 阶段 | 主要参数 | 用途 |
| --- | --- | --- | --- |
| `url` | 请求/响应 | `url`、`raw`、`nc` | 用正则匹配完整 URL。 |
| `path` | 请求/响应 | `path`、`raw` | 精确匹配路径；末尾 `*` 表示前缀匹配。路径未以 `/` 开头时会自动补 `/`。 |
| `reg_path` | 请求/响应 | `path`、`raw`、`nc` | 正则匹配路径。 |
| `reg_param` | 请求/响应 | `param`、`raw`、`nc` | 正则匹配整个查询字符串。 |
| `host` | 请求/响应 | `v`、`split`、`icase` | 精确匹配一个或多个 Host。默认忽略大小写。 |
| `wide_host` | 请求/响应 | `v` | 匹配普通域名或 `*.example.com` 形式的泛域名。`*.example.com` 不包含根域 `example.com`。 |
| `map_host` | 请求/响应 | `file` | 从文件加载主机映射，适合较大的域名集合。 |
| `meth` | 请求/响应 | `meth` | 匹配 HTTP 方法，多个方法按模块支持的列表格式填写。 |
| `dst_port` | 请求/响应 | `port` | 匹配 URL 中的目标端口。 |
| `referer` | 请求 | `host` | 按域名/泛域名列表匹配 Referer 主机。 |
| `time` | 请求 | `time` | 按项目的 cron 时间表达式匹配当前时间。 |
| `try_file` | 请求 | 无 | 检查当前 URL 是否能映射到可用文件。 |

`path` 示例：

```xml
<acl module='path' path='/index.php'/>
<acl module='path' path='/assets/*'/>
<acl module='reg_path' path='^/user/[0-9]+$' nc='0'/>
```

`path` 的普通字符串比较遵循平台文件名比较规则：Windows 通常不区分大小写，Unix/Linux 区分大小写。需要可移植地控制大小写时应使用 `reg_path` 并明确设置 `nc`。

### 地址、端口和负载

| 模块 | 阶段 | 主要参数 | 用途 |
| --- | --- | --- | --- |
| `src` | 请求/响应 | `ip` | 匹配单个客户端 IP/网段规则。 |
| `srcs` | 请求/响应 | `v`、`split` | 匹配 IP、CIDR 或地址范围列表，例如 `127.0.0.1|10.0.0.0/8|192.0.2.1-192.0.2.10`。 |
| `self` | 请求/响应 | `ip` | 匹配接收连接的本地 IP。 |
| `selfs` | 请求/响应 | `v`、`split` | 匹配本地 IP 列表。 |
| `self_port` | 请求/响应 | `port` | 匹配当前连接的本地端口。 |
| `self_ports` | 请求/响应 | `v`、`split` | 匹配本地端口列表。 |
| `listen_ports` | 请求/响应 | `v`、`split` | 匹配实际监听地址的端口列表。 |
| `per_ip` | 请求 | `max` | 当同一客户端 IP 的并发/活动计数达到阈值时匹配。 |
| `loadavg` | 请求/响应 | `maxavg` | 系统负载超过阈值时匹配；Windows 不注册。 |
| `rand` | 请求/响应 | `rand` | 随机抽样匹配，用于灰度或采样规则。 |

### Header、认证和 TLS

| 模块 | 阶段 | 主要参数 | 用途 |
| --- | --- | --- | --- |
| `header` | 请求/响应 | `header`、`val`（或文本）、`nc` | 正则匹配指定 Header。请求阶段读请求头，响应阶段读响应头。 |
| `header_map` | 请求 | `name`、`v`、`split`、`icase` | 将指定请求头与离散值列表匹配。 |
| `auth_user` | 请求/响应 | `v`/文本、`split` | 精确匹配已认证用户名列表。 |
| `reg_auth_user` | 请求/响应 | `user` | 正则匹配已认证用户名。 |
| `ssl_serial` | 请求 | `v`/文本、`split` | 匹配客户端 TLS 证书序列号列表；仅 TLS 构建可用。 |

Header 正则可以写在 `val` 属性或元素文本中：

```xml
<acl module='header' header='User-Agent' val='curl|wget' nc='1'/>
<acl module='header' header='Content-Type' nc='1'>^application/json</acl>
```

### 文件和响应对象

| 模块 | 阶段 | 主要参数 | 用途 |
| --- | --- | --- | --- |
| `file_ext` | 请求/响应 | `v`、`split`、`icase` | 匹配文件扩展名；映射前可从 URL 推断，映射后使用实际文件。 |
| `file` | 响应 | `v`/文本、`split` | 精确匹配已映射的完整文件路径列表。 |
| `filename` | 响应 | `v`、`split`、`icase` | 匹配不含目录的文件名。 |
| `dir` | 响应 | `v`/文本、`split` | 按目录路径前缀匹配已映射文件。 |
| `reg_file` | 响应 | `file`、`nc` | 正则匹配完整文件路径。 |
| `reg_filename` | 响应 | `filename`、`nc` | 正则匹配文件名。 |
| `content_length` | 响应 | `min`、`max` | 匹配响应总长度区间，大小可带单位。 |
| `status_code` | 响应 | `op`、`code` | 匹配状态码；`op` 为 `eq`、`lt` 或 `gt`。 |
| `obj_flag` | 响应 | `flag` | 匹配响应对象标志；当前公开值包含 `nocache`。 |
| `obj_always_on` | 响应 | 无 | 匹配 always-on 对象状态。 |

文件 ACL 通常应放在 `response` 的 `POSTMAP` 表，因为请求入口阶段可能还没有物理文件信息。

### 内部状态和条件 ACL

| 模块 | 阶段 | 主要参数 | 用途 |
| --- | --- | --- | --- |
| `mark` | 请求/响应 | `op`、`v` | 比较请求的数值 mark；`op` 为 `eq`、`lt`、`gt`。 |
| `work_model` | 请求/响应 | `ssl`、`tcp` | 匹配连接工作模式；企业功能。 |
| `ip_rate` | 请求 | `request`、`second` | 按 IP 请求速率匹配；企业功能。 |
| `ip_url_rate` | 请求/响应 | `request`、`second` | 按 IP 与 URL 速率匹配；黑名单功能。 |
| `url_rate` | 请求/响应 | `request`、`second` | 按 URL 速率匹配；黑名单功能。 |
| `cloud_ip` | 请求/响应 | `url`、`flush_time` | 从远程数据维护云 IP 集合；模拟 HTTP 功能。 |
| `white_list` | 请求/响应 | `host`、`flush` | 白名单匹配；企业/Fatboy 功能。 |

条件模块不可用时，配置会找不到相应模型。部署前应在目标二进制的管理界面确认模块已注册。

## 内置 Mark

### 路由、重写和扩展选择

| 模块 | 阶段 | 主要参数 | 用途 |
| --- | --- | --- | --- |
| `rewrite` | 请求 | `prefix`，以及子规则 | Apache 风格的多条 RewriteRule/RewriteCond 重写。 |
| `rewritex` | 请求 | `path`、`dst`、`code`、`nc`、`qsa`、`internal`、`proxy`、`prefix`、`rewrite_base` | 用单条正则完成 URL 重写或跳转。 |
| `url_rewrite` | 请求 | `url`、`dst`、`code`、`nc`（兼容 `icase`） | 正则匹配完整 URL 后重写。 |
| `redirect` | 请求 | `dst`、`code`、`internal` | 跳转到目标 URL；`internal='1'` 进行内部跳转。 |
| `map_redirect` | 请求 | `v` | 依据映射表进行跳转。 |
| `host` | 请求 | `host`、`port`、`life_time`、`reg_host`、`rewrite`、`proxy` | 改写 Host/目标主机；这是 Mark，与同名 Host ACL 含义不同。 |
| `host_rewrite` | 请求 | 模块的主机重写配置 | 重写主机名。 |
| `host_alias` | 请求 | `map` | 应用主机别名映射。 |
| `replace_ip` | 请求 | `ip`、`header`、`sign` | 替换请求使用的客户端 IP。 |
| `parent` | 请求 | `val`、`self_ip` | 设置父级/上游相关地址。 |
| `multi_server` | 请求 | `nodes` | 动态选择多节点服务；企业功能。 |
| `port_map` | 请求 | `host`、`port`、`param` | 四层端口映射；TCP 工作模式构建可用。 |
| `extend_flag` | 请求/响应 | `no_extend` | 设置或清除“禁止扩展处理”标志。 |

`rewritex` 示例：

```xml
<mark module='rewritex' path='^/old/(.*)$' dst='/new/$1'
      nc='0' qsa='1' internal='1'/>
```

`qsa='1'` 保留并追加原查询参数；外部重定向应设置合适的 `code`，内部重写使用 `internal='1'`。复杂 Apache 风格规则使用 `rewrite` 模块及其子项，建议由管理界面生成后再维护 XML。

### Header、参数和 Cookie

| 模块 | 阶段 | 主要参数 | 用途 |
| --- | --- | --- | --- |
| `add_header` | 请求/响应 | `attr`、`val`、`force` | 添加当前阶段的 Header；默认已有同名字段时不添加，`force='1'` 时仍追加。 |
| `remove_header` | 请求/响应 | `attr`、`val` | 删除匹配的 Header。 |
| `replace_header` | 请求/响应 | `attr`、`val`、`replace` | 替换 Header 值。 |
| `add_response_header` | 请求 | `attr`、`val` | 在请求阶段预先添加响应 Header。 |
| `remove_param` | 请求 | `params`、`raw`、`nc` | 从查询参数中删除指定项。 |
| `cookie` | 响应 | `cookie`、`http_only`、`secure` | 修改响应 Cookie 的 HttpOnly/Secure 属性。 |
| `param` | 请求 | 模块的参数过滤配置 | 过滤请求参数；仅输入过滤构建。 |
| `param_count` | 请求 | 模块的计数配置 | 限制参数数量；仅输入过滤构建。 |
| `post_file` | 请求 | 模块的上传文件配置 | 过滤上传文件；仅输入过滤构建。 |

Header 名应使用标准 HTTP 字段名。`remove_header@val` 是忽略大小写的正则，留空删除全部同名字段，以 `!` 开头则删除“不匹配后续正则”的值。`replace_header@val` 是正则，`replace` 可引用捕获结果；该模块只处理遇到的第一个同名字段。

### 缓存、压缩和对象标志

| 模块 | 阶段 | 主要参数 | 用途 |
| --- | --- | --- | --- |
| `cache_control` | 响应 | `max_age`、`force`、`static`、`must_revalidate` | 设置缓存寿命，或在相应构建中强制缓存。 |
| `response_flag` | 响应 | `flagvalue` | 逗号分隔：`compress`、`nocache`、`nodiskcache`、`cache_response`、`identity_encoding`。兼容值 `gzip` 等同 `compress`。 |
| `flag` | 请求/响应 | 多个布尔属性、`clear`、`age` | 设置请求处理标志，详见下一节。 |
| `min_obj_verified` | 请求 | `v`、`hard` | 设置大对象验证阈值。 |
| `vary` | 响应 | `header` | 把指定请求头加入缓存 Vary 维度。 |

`flag` 是历史综合模块，当前源码识别以下属性：

`no_cache`、`no_disk_cache`、`guest`、`always_online`、`ignore_error`、`no_buffer`、`follow_link_all`、`follow_link_own`、`no_x_sendfile`、`no_x_forwarded_for`、`x_real_ip`、`via`、`x_cache`、`proxy_full_url`、`raw_proxy`、`upstream_noka`、`upstream_nosni`、`tproxy_upstream`、`tproxy_trust_dns`、`double_cache_expire`、`log_drill`、`age` 和 `clear`。

这些开关会直接影响缓存、代理和安全行为。除非已理解源码语义，优先使用用途更单一的 Mark；要清除对应标志时使用 `clear='1'`。

### 限速、队列和统计

| 模块 | 阶段 | 主要参数 | 用途 |
| --- | --- | --- | --- |
| `speed_limit` | 请求 | `limit` | 对当前请求限速，支持大小单位。 |
| `gspeed_limit` | 请求 | `limit` | 由共享模块实施全局/规则级聚合限速。 |
| `ip_speed_limit` | 请求 | `speed_limit` | 对每个客户端 IP 分别限速。 |
| `queue` | 请求 | `max_worker`、`max_queue` | 共享请求队列；需请求队列功能。 |
| `per_queue` | 请求 | `max_worker`、`max_queue`、`url` | 按 URL 或 Header 正则分组排队；需请求队列功能。 |
| `counter` | 请求/响应 | 无 | 统计规则执行次数。 |
| `flow` | 请求 | `reset` | 记录流量和缓存命中流量。 |
| `stub_status` | 请求 | 无 | 直接生成状态输出；需状态模块功能。 |

限速值示例：

```xml
<mark module='speed_limit' limit='512K'/>
<mark module='ip_speed_limit' speed_limit='2M'/>
```

### 认证、安全和控制

| 模块 | 阶段 | 主要参数 | 用途 |
| --- | --- | --- | --- |
| `auth` | 请求/响应 | `file`、`crypt_type`、`auth_type`、`realm`、`require`、`file_sign` | 使用密码文件执行 Basic/Digest 认证。 |
| `path_sign` | 请求 | `sign`、`expire`、`key`、`file` | 校验带过期时间的 URL 路径签名。 |
| `mark` | 请求/响应 | `v` | 设置请求数值 mark，供 `mark` ACL 使用。 |
| `timeout` | 请求/响应 | `v` | 设置额外超时周期计数（`0`～`255`）；每个周期使用全局读写超时，并非直接填写秒数。 |
| `connection_close` | 请求/响应 | 无 | 强制本次响应后关闭连接。 |
| `black_list` | 请求 | `enable`、`time_out` | 把客户端加入黑名单；需黑名单功能。 |
| `check_black_list` | 请求 | `enable` | 检查黑名单；需黑名单功能。 |
| `ip_url_rate` | 请求 | `request`、`second`、`block_time` | 速率超限后按 IP/URL 处理；需黑名单功能。 |
| `geo` | 请求 | `name`、`file`、`url`、`flush_time` | 载入地理数据并设置相关变量；企业功能。 |
| `white_list` | 请求/响应 | `host`、`flush` | 更新白名单状态；企业/Fatboy 功能。 |

认证示例：

```xml
<mark module='auth' file='etc/users.passwd' crypt_type='plain'
      auth_type='Basic' realm='Restricted' require='alice,bob'/>
```

`require='*'` 允许密码文件中的任意有效用户；以 `~` 开头可使用用户名正则。认证文件和 `crypt_type` 必须匹配，且 Digest 配置对 realm 和密码摘要格式有额外要求。

## 常见完整示例

### 阻止外部访问管理路径

```xml
<request action='vhs'>
    <table name='BEGIN'>
        <chain action='deny'>
            <acl module='path' path='/admin/*'/>
            <acl module='srcs' v='127.0.0.1|10.0.0.0/8' revers='1'/>
        </chain>
    </table>
</request>
```

### 仅对成功的文本响应设置缓存

```xml
<response action='allow'>
    <table name='BEGIN'>
        <chain action='continue'>
            <acl module='status_code' op='eq' code='200'/>
            <acl module='header' header='Content-Type' val='^text/' nc='1'/>
            <mark module='cache_control' max_age='300' must_revalidate='1'/>
        </chain>
    </table>
</response>
```

### 用命名模块复用安全响应头

```xml
<response action='allow'>
    <named_mark name='nosniff' module='add_header'
                attr='X-Content-Type-Options' val='nosniff' force='1'/>
    <table name='BEGIN'>
        <chain action='continue'>
            <mark ref='nosniff'/>
        </chain>
    </table>
</response>
```

## DSO 自定义模块

DSO 可以通过 `KGL_REGISTER_ACCESS` 注册自定义 ACL/Mark。自定义模块的阶段、参数和返回语义由扩展决定，不属于上表。接口和版本协商见 [DSO 开发文档](./dso.md)。
