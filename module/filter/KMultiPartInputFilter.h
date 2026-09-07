#ifndef KMULTIPARTINPUTFILTER_H
#define KMULTIPARTINPUTFILTER_H
#include <limits.h>
#include <list>
#include "KInputFilter.h"
#include "KHttpHeaderManager.h"
#include "KHttpParser.h"

#define FILLUNIT (1024 * 5)
#define MULTIPART_BOUNDARY_MODEL 0
#define MULTIPART_HEAD_MODEL     1
#define MULTIPART_BODY_MODEL     2

class multipart_buffer {
public:
	multipart_buffer() : buffer(NULL), buf_begin(NULL), bufsize(0),
		bytes_in_buffer(0), boundary(NULL), boundary_next(NULL),
		boundary_next_len(0), model(0) {}
	~multipart_buffer() {
		if (buffer) {
			free(buffer);
		}
		if (boundary) {
			free(boundary);
		}
		if (boundary_next) {
			free(boundary_next);
		}
	}
	bool add(const char* str, int len) {
		if (len < 0 || bytes_in_buffer < 0 || len > INT_MAX - bytes_in_buffer) {
			return false;
		}
		if (buffer) {
			int new_size = len + bytes_in_buffer;
			char* t = (char*)malloc(new_size + 1);
			if (t == NULL) {
				return false;
			}
			kgl_memcpy(t, buf_begin, bytes_in_buffer);
			kgl_memcpy(t + bytes_in_buffer, str, len);
			t[new_size] = '\0';
			free(buffer);
			buffer = t;
			bufsize = new_size;
		} else {
			bufsize = len;
			buffer = (char*)malloc(bufsize + 1);
			if (buffer == NULL) {
				bufsize = 0;
				return false;
			}
			kgl_memcpy(buffer, str, len);
			buffer[bufsize] = '\0';
		}
		bytes_in_buffer = bufsize;
		buf_begin = buffer;
		return true;
	}
	/* read buffer */
	char* buffer;
	char* buf_begin;
	int  bufsize;
	int  bytes_in_buffer;

	/* boundary info */
	char* boundary;
	char* boundary_next;
	int  boundary_next_len;
	int  model;
};
class KMultiPartInputFilter : public KParamInputFilter
{
public:
	KMultiPartInputFilter(const char* val, size_t len);
	~KMultiPartInputFilter() {
		if (hm.header) {
			free_header_list(hm.header);
		}
		if (param) {
			free(param);
		}
		if (filename) {
			free(filename);
		}
		if (mb) {
			delete mb;
		}
	}
	bool match(KInputFilterContext* rq, const char* str, int len, bool isLast) override;
	bool register_file(KFileFilterHook* filter) override {
		file_list.emplace_back(filter->add_ref());
		return true;
	}
private:
	bool match_body(bool& success);
	char* parse_body(int* len, bool& all);
	kgl_parse_result parse_header();
	bool match_file_content(const char* buf, int len);
	KHttpHeaderManager hm;
	char* param;
	int param_len;
	char* filename;
	int filename_len;
	std::list<KFileFilter> file_list;
	multipart_buffer* mb = nullptr;
};
#endif
