#ifndef KCONTENTFILTERMARKS_H
#define KCONTENTFILTERMARKS_H

#include "KMark.h"
#include "KReg.h"

class KReplaceContentMark final : public KMark {
public:
	KReplaceContentMark();
	KMark* new_instance() override;
	const char* get_module() const override;
	uint32_t process(KHttpRequest* rq, KHttpObject* obj, KSafeSource& fo) override;
	void get_display(KWStream& s) override;
	void get_html(KWStream& s) override;
	void parse_config(const khttpd::KXmlNodeBody* xml) override;

	int match(const char* data, int length, kgl_pcre_match_data* match_data);
	KStringStream* make_replacement(KHttpRequest* rq, const char* data,
		kgl_pcre_match_data* match_data, int matched) const;
	bool should_process(KHttpRequest* rq) const;
	void update_mark(KHttpRequest* rq) const;
	size_t max_pending() const;
	bool stop_after_replace() const;

private:
	KReg content;
	KString content_text;
	KString replace_text;
	KString charset;
	KString charset_replace;
	int mark_acl;
	int mark_mark;
	bool nc;
	bool replaced_stop;
	size_t buffer;
	bool valid;
};

class KRegContentMark final : public KMark {
public:
	KRegContentMark();
	KMark* new_instance() override;
	const char* get_module() const override;
	uint32_t process(KHttpRequest* rq, KHttpObject* obj, KSafeSource& fo) override;
	uint32_t process_with_chain(KHttpRequest* rq, KHttpObject* obj,
		KSafeSource& fo, kgl_jump_type chain_jump_type) override;
	void get_display(KWStream& s) override;
	void get_html(KWStream& s) override;
	void parse_config(const khttpd::KXmlNodeBody* xml) override;

	KReg* select_reg(const char* response_charset);
	size_t max_pending() const;

private:
	KReg utf8_reg;
	KReg local_reg;
	KString expression;
	KString charset;
	size_t buffer;
	bool utf8_valid;
	bool local_valid;
};

class KReplaceUrlMark final : public KMark {
public:
	KReplaceUrlMark();
	KMark* new_instance() override;
	const char* get_module() const override;
	uint32_t process(KHttpRequest* rq, KHttpObject* obj, KSafeSource& fo) override;
	void get_display(KWStream& s) override;
	void get_html(KWStream& s) override;
	void parse_config(const khttpd::KXmlNodeBody* xml) override;

	KStringStream* replace(KHttpRequest* rq, const char* url, int length);
	bool is_valid() const;

private:
	KReg src;
	KString src_text;
	KString dst;
	bool nc;
	bool location;
	bool valid;
};

class KFixHeaderMark final : public KMark {
public:
	KFixHeaderMark();
	KMark* new_instance() override;
	const char* get_module() const override;
	uint32_t process(KHttpRequest* rq, KHttpObject* obj, KSafeSource& fo) override;
	void get_display(KWStream& s) override;
	void get_html(KWStream& s) override;
	void parse_config(const khttpd::KXmlNodeBody* xml) override;

private:
	KString header;
};

#endif
