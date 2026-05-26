object FrmMySqlSync: TFrmMySqlSync
  Left = 0
  Top = 0
  BorderStyle = bsSingle
  Caption = #931#965#947#967#961#959#957#953#963#956#972#962' '#945#961#967#949#943#969#957' MySQL'
  ClientHeight = 154
  ClientWidth = 438
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  FormStyle = fsMDIChild
  OldCreateOrder = False
  Visible = True
  DesignSize = (
    438
    154)
  PixelsPerInch = 96
  TextHeight = 13
  object lblState: TLabel
    Left = 8
    Top = 16
    Width = 152
    Height = 23
    Caption = #928#953#941#963#964#949' "'#917#954#954#943#957#951#963#951'"'
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
  end
  object lblShow: TLabel
    Left = 8
    Top = 72
    Width = 422
    Height = 23
    Anchors = [akLeft, akTop, akRight]
    AutoSize = False
    Caption = #913#957#945#956#941#957#959#957#964#945#953' '#948#949#948#959#956#941#957#945'...'
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    ExplicitWidth = 420
  end
  object progress: TJvSpecialProgress
    Left = 8
    Top = 101
    Width = 422
    Height = 15
    Anchors = [akLeft, akTop, akRight]
    Caption = 'progress'
    Solid = True
    ExplicitWidth = 523
  end
  object cmdAuthentication: TJvDotNetButton
    Left = 334
    Top = 122
    Width = 96
    Height = 20
    Anchors = [akTop, akRight]
    Caption = #916#953#945#954#959#960#942
    Font.Charset = GREEK_CHARSET
    Font.Color = clBlack
    Font.Height = -15
    Font.Name = 'Tahoma'
    Font.Style = [fsBold]
    ParentFont = False
    TabOrder = 0
    OnClick = cmdAuthenticationClick
  end
  object JvDotNetButton1: TJvDotNetButton
    Left = 8
    Top = 122
    Width = 96
    Height = 20
    Caption = #917#954#954#943#957#951#963#951
    Font.Charset = GREEK_CHARSET
    Font.Color = clBlack
    Font.Height = -15
    Font.Name = 'Tahoma'
    Font.Style = [fsBold]
    ParentFont = False
    TabOrder = 1
    OnClick = JvDotNetButton1Click
  end
  object QueryInsert: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    ParamCheck = True
    SQL.Strings = (
      'INSERT INTO PRODUCT '
      
        '(PRODUCT_ID, BARCODE, DESCRIPTION_SHORT, CAT_ID, VATCAT_ID, METR' +
        'IC_ID, SELL_PRICE, PRICE_WVAT, RESERVE)'
      
        'VALUES (:PRODUCT_ID, :BARCODE, :DESCRIPTION_SHORT, :CAT_ID, :VAT' +
        'CAT_ID, :METRIC_ID, :PRICE_WVAT, :PRICE_WVAT, :RESERVE)')
    Left = 300
    Top = 32
    ParamData = <
      item
        DataType = ftUnknown
        Name = 'PRODUCT_ID'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'BARCODE'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'DESCRIPTION_SHORT'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'CAT_ID'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'VATCAT_ID'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'METRIC_ID'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'PRICE_WVAT'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'PRICE_WVAT'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'RESERVE'
        ParamType = ptUnknown
      end>
  end
  object QueryUpdate: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    ParamCheck = True
    SQL.Strings = (
      'UPDATE PRODUCT'
      'SET '
      'BARCODE = :BARCODE,'
      'DESCRIPTION_SHORT = :DESCRIPTION_SHORT,'
      'PRICE_WVAT = :PRICEWVAT,'
      'RESERVE = :RESERVE,'
      'SELL_PRICE = :SELL_PRICE'
      'WHERE'
      'PRODUCT_ID = :PRODUCT_ID')
    Left = 332
    Top = 32
    ParamData = <
      item
        DataType = ftUnknown
        Name = 'BARCODE'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'DESCRIPTION_SHORT'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'PRICEWVAT'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'RESERVE'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'SELL_PRICE'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'PRODUCT_ID'
        ParamType = ptUnknown
      end>
  end
  object sqlConnection: TSQLConnection
    ConnectionName = 'MySQLConnection'
    DriverName = 'MySQL'
    LoginPrompt = False
    Params.Strings = (
      'DriverUnit=DBXMySQL'
      
        'DriverPackageLoader=TDBXDynalinkDriverLoader,DbxCommonDriver140.' +
        'bpl'
      'VendorLib=LIBMYSQL.dll')
    TableScope = [tsTable]
    Left = 176
    Top = 32
  end
  object Query: TSQLQuery
    MaxBlobSize = -1
    ParamCheck = False
    Params = <>
    SQL.Strings = (
      
        'SELECT DISTINCT cscart_products.* , cscart_product_descriptions.' +
        'product, cscart_product_prices.price'
      '                   FROM cscart_products'
      
        '  LEFT join cscart_product_descriptions on cscart_products.produ' +
        'ct_id = cscart_product_descriptions.product_id'
      
        ' LEFT join cscart_product_prices on cscart_products.product_id =' +
        ' cscart_product_prices.product_id'
      
        '  WHERE lang_code = '#39'GR'#39' AND cscart_products.product_code is not' +
        ' null AND recordChanged = 1'
      ' GROUP BY cscart_products.product_code'
      ' ORDER BY cscart_products.product_id'
      '')
    SQLConnection = sqlConnection
    Left = 208
    Top = 32
  end
end
