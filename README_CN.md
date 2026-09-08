# kangle

[English](README.md) | 简体中文

<img src="webadmin/logo.gif" alt="kangle logo" width="180"/>

kangle 是一款轻量、高性能的 Web 服务器和反向代理软件，内置 Web
管理控制台，并提供灵活的请求/响应访问控制、虚拟主机、内存及磁盘缓存和多种上游协议支持。

## 主要功能

- 支持 HTTP/1.1、HTTP/2，以及可选的 HTTP/3
- 支持 HTTP、HTTP/2、FastCGI、AJP 上游及连接复用
- 支持内存缓存、磁盘缓存和大文件分段缓存
- 完整的请求和响应访问控制规则
- 虚拟主机进程及运行用户隔离
- XML 配置文件和 Web 管理控制台
- 支持动态 gzip、Brotli、Zstandard 压缩
- 支持 DSO 扩展、WebDAV 和基于 SQLite 的虚拟主机模块

## 编译要求

完整项目实际要求 **CMake 3.12 或更高版本**，因为 `khttpd` 子模块最低要求
CMake 3.12。虽然根目录当前仍声明了更早的最低版本，但 CMake 2.x 无法配置完整源码树。

必须安装以下工具和开发库：

- Git，并支持拉取 Git 子模块
- CMake 3.12 或更高版本（CentOS/RHEL 7 使用 `cmake3`）
- GNU Make 或 Ninja
- 支持 C++17 的编译器，建议使用较新的 GCC 或 Clang
- OpenSSL 开发库
- zlib 开发库
- PCRE2 开发库，也可以使用 PCRE 8.x
- Unix 类系统需要 POSIX Threads

SQLite 已包含在源码中。系统不存在 SQLite 开发库时，会自动编译并使用内置版本。

### Debian、Ubuntu

```bash
sudo apt update
sudo apt install -y \
  git build-essential cmake pkg-config \
  libssl-dev zlib1g-dev libpcre2-dev
```

### Rocky Linux、AlmaLinux、RHEL、Fedora

```bash
sudo dnf install -y \
  git gcc gcc-c++ make cmake pkgconf-pkg-config \
  openssl-devel zlib-devel pcre2-devel
```

### CentOS/RHEL 7

系统自带的 `cmake` 版本过低，需要安装 EPEL 提供的 `cmake3`，配置项目时也必须使用
`cmake3` 命令：

```bash
sudo yum install -y epel-release
sudo yum install -y \
  git gcc gcc-c++ make cmake3 \
  openssl-devel zlib-devel pcre2-devel
```

## 从源码编译

请递归克隆仓库，`kasync` 和 `khttpd` 是必需的子模块。

```bash
git clone --recursive https://github.com/cccyun/kangle.git
cd kangle

mkdir cmake-build
cd cmake-build
cmake .. -DCMAKE_BUILD_TYPE=Release
make -j"$(nproc)"
```

CentOS/RHEL 7 需要将 `cmake` 替换为 `cmake3`：

```bash
cmake3 .. -DCMAKE_BUILD_TYPE=Release
make -j"$(nproc)"
```

如果克隆时没有添加 `--recursive`，请在配置前初始化子模块：

```bash
git submodule update --init --recursive
```

即使使用单独的 CMake 构建目录，最终程序仍会输出到仓库的 `build/` 目录。可以用下面的命令检查已启用的功能：

```bash
../build/kangle -v
```

## 安装和运行

以下命令会将 kangle 安装到 `/usr/local`。如需修改安装位置，在执行 CMake 时添加
`-DCMAKE_INSTALL_PREFIX=/目标目录`。

```bash
sudo make install
sudo install -d /usr/local/var /usr/local/ext /usr/local/www
sudo cp -n /usr/local/etc/config-default.xml /usr/local/etc/config.xml
```

对外提供服务前，请检查 `/usr/local/etc/config.xml` 中的监听地址和管理密码。默认配置监听
80 端口，管理接口仅监听 `127.0.0.1:3311`。

```bash
# 以守护进程方式启动
sudo /usr/local/bin/kangle

# 平滑加载配置
sudo /usr/local/bin/kangle -r

# 停止服务
sudo /usr/local/bin/kangle -q

# 前台运行，适合容器或故障排查
sudo /usr/local/bin/kangle -n -g
```

项目目前不会自动安装 systemd 服务单元。发行版安装包或自定义服务单元可以调用上面的命令。

## 可选编译功能

启用系统库前需要安装相应的开发包。常见包名包括
`libbrotli-dev`/`brotli-devel`、`libzstd-dev`/`libzstd-devel`、
`libjemalloc-dev`/`jemalloc-devel` 和 `liburing-dev`/`liburing-devel`。

| 选项 | 作用 |
| --- | --- |
| `-DENABLE_BROTLI=ON` | 使用系统 Brotli 库 |
| `-DBROTLI_DIR=/path/to/brotli` | 从指定源码目录编译 Brotli |
| `-DENABLE_ZSTD=ON` | 使用系统 Zstandard 库 |
| `-DZSTD_DIR=/path/to/zstd` | 从指定源码目录编译 Zstandard |
| `-DENABLE_JEMALLOC=ON` | 链接系统 jemalloc 库 |
| `-DLINUX_IOURING=ON` | 启用 Linux io_uring 支持 |
| `-DHTTP_PROXY=ON` | 编译以代理功能为主的版本 |
| `-DENABLE_DISK_CACHE=OFF` | 禁用磁盘缓存 |
| `-DENABLE_HTTP2=OFF` | 禁用 HTTP/2 |
| `-DKSOCKET_SSL=OFF` | 不编译 TLS 支持 |

例如，编译同时支持系统 Brotli 和 Zstandard 库的 Release 版本：

```bash
cmake .. \
  -DCMAKE_BUILD_TYPE=Release \
  -DENABLE_BROTLI=ON \
  -DENABLE_ZSTD=ON
make -j"$(nproc)"
```

## 编译 HTTP/3

HTTP/3 同时需要 BoringSSL 和 LSQUIC 源码。建议将它们放在 kangle 仓库外，然后将路径传给
CMake。LSQUIC 本身还有一些平台编译依赖，请先根据所用系统安装这些依赖。

```bash
mkdir kangle-http3 && cd kangle-http3
git clone --recursive https://github.com/google/boringssl.git
git clone --recursive https://github.com/litespeedtech/lsquic.git
git clone --recursive https://github.com/cccyun/kangle.git

cd kangle
mkdir cmake-build && cd cmake-build
cmake .. \
  -DCMAKE_BUILD_TYPE=Release \
  -DBORINGSSL_DIR=../../boringssl \
  -DLSQUIC_DIR=../../lsquic
make -j"$(nproc)"
```

不能只设置 `LSQUIC_DIR`；当前构建逻辑只有在同时设置 `BORINGSSL_DIR` 时才会启用 LSQUIC。

## 完整测试

集成测试要求 **Go 1.22 或更高版本**，第一次编译时需要下载 Go 模块。先完成 kangle
编译，然后执行：

```bash
cd test
./build.sh
./test.sh
```

测试程序会启动多个本地监听端口和辅助进程，请确认测试端口没有被其他程序占用。未编译的功能（例如 Brotli 或 HTTP/3）会在测试输出中提示并自动跳过。

## 文档

- [配置说明](docs/config.md)
- [DSO 扩展开发](docs/dso.md)
- [开源许可证](LICENSE)
