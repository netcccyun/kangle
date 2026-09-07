#include "KDiskCacheStream.h"
#include "KHttpRequest.h"
#include "KHttpObject.h"
#ifdef ENABLE_DISK_CACHE

bool KDiskCacheStream::Open(KHttpObject *obj)
{
	/* Keep a successfully closed cache file, but discard an incomplete one. */
	reset(fp != nullptr);
	filename = obj->get_filename().release();
	if (filename == nullptr) {
		return false;
	}
	fp = kfiber_file_open(filename, fileWrite, 0);
	if (fp == nullptr) {
		reset(false);
		return false;
	}
	if (!kasync_file_direct(fp,true)) {
		reset(true);
		return false;
	}
	if (kfiber_file_seek(fp, seekBegin, obj->GetHeaderSize(0)) != 0) {
		reset(true);
		return false;
	}
	return true;
}
int64_t KDiskCacheStream::GetLength(KHttpObject* obj)
{
	assert(fp);
	return kfiber_file_tell(fp) - obj->index.head_size;
}
bool KDiskCacheStream::Write(KHttpObject *obj, const char *buf, int len)
{
	if (fp == nullptr || len < 0 || (len > 0 && buf == nullptr)) {
		return false;
	}
	while (len > 0) {
		if (buffer == NULL) {
			buffer_size = conf.io_buffer;
			if (buffer_size < 4096) {
				buffer_size = 4096;
			}
			buffer_left = buffer_size;
			buffer = (char *)aio_alloc_buffer(buffer_size);
			if (buffer == nullptr) {
				buffer_left = buffer_size = 0;
				return false;
			}
			hot = buffer;
		}
		int this_len = KGL_MIN(buffer_left, len);
		memcpy(hot, buf, this_len);
		hot += this_len;
		buf += this_len;
		buffer_left -= this_len;
		len -= this_len;
		if (buffer_left <= 0) {
			if (!FlushBuffer()) {
				return false;
			}
		}
	}
	return true;
}

bool KDiskCacheStream::Close(KHttpObject *obj)
{
	if (fp == nullptr || !FlushBuffer()) {
		return false;
	}
	int64_t content_length = GetLength(obj);
	if (content_length < 0) {
		return false;
	}
	obj->index.content_length = content_length;
	kfiber_file_close(fp);
	fp = NULL;
	return true;
}
bool KDiskCacheStream::FlushBuffer()
{
	if (fp == nullptr) {
		return false;
	}
	if (buffer == nullptr) {
		return true;
	}
	int size = (int)(hot - buffer);
	hot = buffer;
	while (size > 0) {
		int got = kfiber_file_write(fp, hot, size);
		if (got <= 0) {
			return false;
		}
		hot += got;
		size -= got;
	}
	hot = buffer;
	buffer_left = buffer_size;
	return true;
}
#endif
