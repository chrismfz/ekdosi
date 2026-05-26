object FrmShowNewBalance: TFrmShowNewBalance
  Left = 0
  Top = 0
  BorderIcons = [biMinimize, biMaximize]
  Caption = #933#960#972#955#959#953#960#945' & '#960#955#951#961#969#956#941#962
  ClientHeight = 329
  ClientWidth = 379
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
    379
    329)
  PixelsPerInch = 96
  TextHeight = 13
  object lblOldBalance: TLabel
    Left = 248
    Top = 190
    Width = 110
    Height = 23
    Alignment = taRightJustify
    Anchors = [akRight, akBottom]
    AutoSize = False
    Caption = '0,00 '#8364
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    ExplicitTop = 168
  end
  object Label11: TLabel
    Left = 56
    Top = 190
    Width = 202
    Height = 23
    Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
    Alignment = taRightJustify
    Anchors = [akRight, akBottom]
    Caption = #928#961#959#951#947#959#973#956#949#957#959' '#933#960#972#955#959#953#960#959':'
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    ExplicitTop = 168
  end
  object lblNewBalance: TLabel
    Left = 248
    Top = 227
    Width = 110
    Height = 23
    Alignment = taRightJustify
    Anchors = [akRight, akBottom]
    AutoSize = False
    Caption = '0,00 '#8364
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    ExplicitTop = 205
  end
  object Label2: TLabel
    Left = 137
    Top = 227
    Width = 121
    Height = 23
    Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
    Alignment = taRightJustify
    Anchors = [akRight, akBottom]
    Caption = #925#941#959' '#933#960#972#955#959#953#960#959':'
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    ExplicitTop = 205
  end
  object Label3: TLabel
    Left = 166
    Top = 78
    Width = 92
    Height = 23
    Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
    Alignment = taRightJustify
    Anchors = [akRight, akBottom]
    Caption = #928#955#951#961#969#964#941#959':'
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    ExplicitTop = 56
  end
  object lblCharge: TLabel
    Left = 248
    Top = 78
    Width = 110
    Height = 23
    Alignment = taRightJustify
    Anchors = [akRight, akBottom]
    AutoSize = False
    Caption = '0,00 '#8364
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    ExplicitTop = 56
  end
  object Label5: TLabel
    Left = 174
    Top = 118
    Width = 84
    Height = 23
    Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
    Alignment = taRightJustify
    Anchors = [akRight, akBottom]
    Caption = #917#943#963#960#961#945#958#951':'
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    ExplicitTop = 96
  end
  object Label1: TLabel
    Left = 7
    Top = 14
    Width = 77
    Height = 23
    Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
    Alignment = taRightJustify
    Anchors = [akRight, akBottom]
    Caption = #928#949#955#940#964#951#962':'
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
  end
  object lblCustName: TLabel
    Left = 90
    Top = 18
    Width = 281
    Height = 38
    Anchors = [akRight, akBottom]
    AutoSize = False
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -16
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    WordWrap = True
  end
  object editPayment: TJvValidateEdit
    Left = 263
    Top = 118
    Width = 99
    Height = 23
    Anchors = [akRight, akBottom]
    AutoSize = False
    CriticalPoints.MaxValueIncluded = False
    CriticalPoints.MinValueIncluded = False
    DisplayFormat = dfCurrency
    DecimalPlaces = 2
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -16
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    TabOrder = 0
    OnExit = editPaymentExit
  end
  object JvDotNetButton1: TJvDotNetButton
    Left = 259
    Top = 296
    Width = 99
    Height = 25
    Hint = #922#945#964#945#967#974#961#951#963#951' '#960#945#961#945#963#964#945#964#953#954#959#973' '#954#945#953' '#949#954#964#973#960#969#963#951
    Anchors = [akRight, akBottom]
    Caption = #917#957#964#940#958#949#953
    TabOrder = 1
    OnClick = JvDotNetButton1Click
  end
  object QryInvoice: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    ParamCheck = True
    SQL.Strings = (
      
        'SELECT INVOICE.*,CUSTOMER.NAME FROM INVOICE INNER JOIN CUSTOMER ' +
        'ON INVOICE.CUST_ID = CUSTOMER.CUST_ID WHERE INVOICE_ID = :INVOIC' +
        'EID')
    Left = 28
    Top = 68
    ParamData = <
      item
        DataType = ftUnknown
        Name = 'INVOICEID'
        ParamType = ptUnknown
      end>
    object QryInvoiceINVOICE_ID: TIntegerField
      FieldName = 'INVOICE_ID'
      Origin = '"INVOICE"."INVOICE_ID"'
      ProviderFlags = [pfInUpdate, pfInWhere, pfInKey]
      Required = True
    end
    object QryInvoiceINVCODE: TIBStringField
      FieldName = 'INVCODE'
      Origin = '"INVOICE"."INVCODE"'
      Required = True
      Size = 15
    end
    object QryInvoiceCUST_ID: TIntegerField
      FieldName = 'CUST_ID'
      Origin = '"INVOICE"."CUST_ID"'
    end
    object QryInvoiceINVTYPE: TIBStringField
      FieldName = 'INVTYPE'
      Origin = '"INVOICE"."INVTYPE"'
      Size = 6
    end
    object QryInvoiceINVDATE: TDateField
      FieldName = 'INVDATE'
      Origin = '"INVOICE"."INVDATE"'
    end
    object QryInvoicePRINTED: TSmallintField
      FieldName = 'PRINTED'
      Origin = '"INVOICE"."PRINTED"'
    end
    object QryInvoiceDELIVERYDATE: TDateField
      FieldName = 'DELIVERYDATE'
      Origin = '"INVOICE"."DELIVERYDATE"'
    end
    object QryInvoiceDISTRAIM_ID: TIntegerField
      FieldName = 'DISTRAIM_ID'
      Origin = '"INVOICE"."DISTRAIM_ID"'
    end
    object QryInvoiceDELMETHOD_ID: TIntegerField
      FieldName = 'DELMETHOD_ID'
      Origin = '"INVOICE"."DELMETHOD_ID"'
    end
    object QryInvoicePAYMETH_ID: TIntegerField
      FieldName = 'PAYMETH_ID'
      Origin = '"INVOICE"."PAYMETH_ID"'
    end
    object QryInvoiceDISCOUNT: TIBBCDField
      FieldName = 'DISCOUNT'
      Origin = '"INVOICE"."DISCOUNT"'
      Precision = 18
      Size = 2
    end
    object QryInvoicePRICE: TIBBCDField
      FieldName = 'PRICE'
      Origin = '"INVOICE"."PRICE"'
      Precision = 18
      Size = 2
    end
    object QryInvoicePRICEWVAT: TIBBCDField
      FieldName = 'PRICEWVAT'
      Origin = '"INVOICE"."PRICEWVAT"'
      Precision = 18
      Size = 2
    end
    object QryInvoiceNOTES: TMemoField
      FieldName = 'NOTES'
      Origin = '"INVOICE"."NOTES"'
      ProviderFlags = [pfInUpdate]
      BlobType = ftMemo
      Size = 8
    end
    object QryInvoiceNAME: TIBStringField
      FieldName = 'NAME'
      Origin = '"CUSTOMER"."NAME"'
      Required = True
      Size = 50
    end
  end
  object QryBalance: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    ParamCheck = True
    SQL.Strings = (
      'SELECT * FROM GET_CUSTOMER_OLD_BALANCE(:XINVOICE_ID)')
    Left = 28
    Top = 100
    ParamData = <
      item
        DataType = ftUnknown
        Name = 'XINVOICE_ID'
        ParamType = ptUnknown
      end>
    object QryBalanceINVOICE_PRICE: TIBBCDField
      FieldName = 'INVOICE_PRICE'
      Origin = '"GET_CUSTOMER_OLD_BALANCE"."INVOICE_PRICE"'
      Precision = 18
      Size = 2
    end
    object QryBalanceOLD_BALANCE: TIBBCDField
      FieldName = 'OLD_BALANCE'
      Origin = '"GET_CUSTOMER_OLD_BALANCE"."OLD_BALANCE"'
      Precision = 18
      Size = 2
    end
    object QryBalanceNEW_BALANCE: TIBBCDField
      FieldName = 'NEW_BALANCE'
      Origin = '"GET_CUSTOMER_OLD_BALANCE"."NEW_BALANCE"'
      Precision = 18
      Size = 2
    end
  end
end
