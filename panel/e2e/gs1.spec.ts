import { expect, test } from '@playwright/test';
import { HibcPairing, isHibcPrimaryOnly, parseGs1 } from '../src/app/core/gs1';

/**
 * Karekod çözücü birim testleri (tarayıcı açmaz). mobile/test/gs1_test.dart ile aynı örnekler:
 * mobil ve panel aynı etikette aynı sonucu vermeli.
 * 08691234567890: kontrol hanesi doğru GTIN-14. HIBC kontrol karakterleri ANSI/HIBC Ek B (mod 43).
 */
test.describe('parseGs1 · GS1', () => {
  test('parantezli karekod', () => {
    expect(parseGs1('(01)08691234567890(17)271231(10)LOT42(21)SN001')).toEqual({
      gtin: '08691234567890', expiry: '2027-12-31', lot: 'LOT42', serial: 'SN001',
    });
  });

  test('ham karekod; gün 00 ayın son günü; tarayıcı öneki atılır', () => {
    expect(parseGs1(']d201086912345678901727120010LOT42\x1d21SN001')).toEqual({
      gtin: '08691234567890', expiry: '2027-12-31', lot: 'LOT42', serial: 'SN001',
    });
    expect(parseGs1('(01)08691234567890(17)280200(10)A').expiry).toBe('2028-02-29');
  });

  test('lot ve SKT\'den önce gelen ek alanlar (240, 30) okumayı kesmez', () => {
    expect(parseGs1('010869123456789024012345-X\x1d305\x1d1727123110LOT42\x1d21SN001')).toEqual({
      gtin: '08691234567890', expiry: '2027-12-31', lot: 'LOT42', serial: 'SN001',
    });
  });

  test('üretim tarihi ve yüzyıl kuralı', () => {
    const now = new Date(2026, 10, 17);
    expect(parseGs1('(01)08691234567890(11)250115(10)A').produced).toBe('2025-01-15');
    expect(parseGs1('(01)08691234567890(17)601231', now).expiry).toBe('2060-12-31');
    expect(parseGs1('(01)08691234567890(17)991231', now).expiry).toBe('1999-12-31');
  });

  test('GTIN kontrol hanesi hatalıysa reddedilir; geçersiz tarih alınmaz', () => {
    const bad = parseGs1('(01)08691234567893(10)LOT42');
    expect(bad.gtin).toBeUndefined();
    expect(bad.error).toContain('kontrol hanesi');
    expect(parseGs1('(01)08691234567890(17)271340(10)A').expiry).toBeUndefined();
  });

  test('GS1 Digital Link', () => {
    expect(parseGs1('https://id.gs1.org/01/08691234567890/10/LOT42/21/SN001?17=271231')).toEqual({
      gtin: '08691234567890', expiry: '2027-12-31', lot: 'LOT42', serial: 'SN001',
    });
  });

  test('düz kodlar', () => {
    expect(parseGs1('8691234567890')).toEqual({ plain: '8691234567890', gtin: '08691234567890' });
    expect(parseGs1('12345678')).toEqual({ plain: '12345678' });
    expect(parseGs1('URN-ABC')).toEqual({ plain: 'URN-ABC' });
    expect(parseGs1('10-4455')).toEqual({ plain: '10-4455' });
    expect(parseGs1('   ')).toEqual({});
  });
});

test.describe('parseGs1 · HIBC', () => {
  test('resmi örnek: +A123BJC5D6E71G', () => {
    expect(parseGs1('+A123BJC5D6E71G')).toEqual({ plain: '+A123BJC5D6E71', hibcCheck: 'G' });
  });

  test('ikincil veri biçimleri', () => {
    expect(parseGs1('+H123ABC01/$$3271231LOT42E')).toEqual({ plain: '+H123ABC01', expiry: '2027-12-31', lot: 'LOT42' });
    expect(parseGs1('+H123ABC01/$$1227LOT427')).toEqual({ plain: '+H123ABC01', expiry: '2027-12-31', lot: 'LOT42' });
    expect(parseGs1('+H123ABC01/$$+3271231SN001R')).toEqual({ plain: '+H123ABC01', expiry: '2027-12-31', serial: 'SN001' });
    expect(parseGs1('+H123ABC01/$LOT42%')).toEqual({ plain: '+H123ABC01', lot: 'LOT42' });
    expect(parseGs1('+H123ABC01/$$8053271231LOT42R')).toEqual({ plain: '+H123ABC01', expiry: '2027-12-31', lot: 'LOT42' });
    expect(parseGs1('+H123ABC01/$$3271231LOT42/16D202501154').produced).toBe('2025-01-15');
  });

  test('kontrol karakteri tutmazsa reddedilir', () => {
    const bad = parseGs1('+H123ABC01/$$3271231LOT42X');
    expect(bad.plain).toBeUndefined();
    expect(bad.error).toContain('kontrol');
  });

  test('ayrı basılmış ürün ve lot barkodu tanınır', () => {
    const p = parseGs1('+H123ABC01C');
    expect(isHibcPrimaryOnly(p)).toBe(true);
    expect(p.hibcCheck).toBe('C');
    // sondan ikinci karakter bağ karakteri = ürün barkodunun kontrol karakteri
    expect(parseGs1('+$$3271231LOT42CF')).toEqual({ hibcLink: 'C', lot: 'LOT42', expiry: '2027-12-31' });
    expect(isHibcPrimaryOnly(parseGs1('+H123ABC01/$$3271231LOT42E'))).toBe(false);
  });
});

test.describe('HibcPairing', () => {
  const primary = '+H123ABC01C';
  const secondary = '+$$3271231LOT42CF';

  test('önce ürün sonra lot: lot takipli ürün bekletilir, ikisi birleşir', () => {
    const pair = new HibcPairing();
    expect(pair.holdIfNeeded(pair.feed(parseGs1(primary))!, 'lot')).toBe(true);
    expect(pair.feed(parseGs1(secondary))).toEqual({ plain: '+H123ABC01', lot: 'LOT42', expiry: '2027-12-31' });
    expect(pair.waiting).toBe(false);
  });

  test('önce lot sonra ürün', () => {
    const pair = new HibcPairing();
    expect(pair.feed(parseGs1(secondary))).toBeUndefined();
    expect(pair.message).toContain('ürün barkodunu okutun');
    expect(pair.messageIsError).toBe(false);
    const g = pair.feed(parseGs1(primary))!;
    expect(g.lot).toBe('LOT42');
    expect(pair.holdIfNeeded(g, 'lot')).toBe(false);
  });

  test('bağ karakteri tutmazsa birleşmez, ürün beklemeye devam eder', () => {
    const pair = new HibcPairing();
    pair.holdIfNeeded(pair.feed(parseGs1(primary))!, 'lot');
    expect(pair.feed(parseGs1('+$$3271231LOT42X-'))).toBeUndefined();
    expect(pair.message).toContain('ait değil');
    expect(pair.messageIsError).toBe(true);
    expect(pair.feed(parseGs1(secondary))?.lot).toBe('LOT42');
  });

  test('lot barkodu yoksa ürün barkodu ikinci kez okutulunca lotsuz devam edilir', () => {
    const pair = new HibcPairing();
    expect(pair.holdIfNeeded(pair.feed(parseGs1(primary))!, 'lot')).toBe(true);
    const again = pair.feed(parseGs1(primary))!;
    expect(again.lot).toBeUndefined();
    expect(pair.holdIfNeeded(again, 'lot')).toBe(false);
  });

  test('lot takibi olmayan ürün beklemez; başka barkod bekleyeni iptal eder', () => {
    const pair = new HibcPairing();
    expect(pair.holdIfNeeded(pair.feed(parseGs1(primary))!, 'none')).toBe(false);
    pair.holdIfNeeded(pair.feed(parseGs1(primary))!, 'lot');
    expect(pair.feed(parseGs1('(01)08691234567890(10)A'))?.gtin).toBe('08691234567890');
    expect(pair.waiting).toBe(false);
  });
});
